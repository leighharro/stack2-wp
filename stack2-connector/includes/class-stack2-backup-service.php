<?php

if (!defined('ABSPATH')) {
    exit;
}

class Stack2_Backup_Service
{
    public const CHUNK_SIZE     = 10485760; // 10 MB per chunk
    public const EXPIRY_SECONDS = 3600;     // orphan temp files cleaned up after 1 hour
    public const SQL_BATCH_SIZE = 1000;     // rows per SELECT batch when dumping tables
    /** Raw regex fragment (no anchors/delimiters) for backup ID validation. */
    public const BACKUP_ID_RAW_REGEX = 'bkp_[a-f0-9]{32}';
    private const BACKUP_DIR_NAME   = 'stack2-backups';
    private const BACKUP_ID_PATTERN = '/^bkp_[a-f0-9]{32}$/';

    private Stack2_Logger $logger;

    // ── Per-backup streaming state ────────────────────────────────────────────
    // Reset at the start of each generate_and_push() call.  These track the
    // hold-back buffer used to flag the very last chunk with is_last=true.
    private int    $next_chunk_index = 0;
    private int    $total_bytes      = 0;
    private ?array $buffered_chunk   = null;
    private ?string $push_error      = null;

    public function __construct(Stack2_Logger $logger)
    {
        $this->logger = $logger;
    }

    /**
     * Stream the backup directly to the Stack2 API without writing a large
     * archive to disk.
     *
     * Each file under ABSPATH is read in CHUNK_SIZE pieces and pushed
     * immediately.  The only temp file created is a small SQL dump for the
     * database portion, which is deleted right after its chunks are pushed.
     * No ZIP archive is ever materialised on disk.
     *
     * Chunk payload format:
     *   backup_id        – shared identifier for the whole backup session
     *   chunk_index      – 0-based sequential index across ALL chunks
     *   file_path        – virtual path of the source file (e.g.
     *                      "database/database.sql" or "wordpress/wp-config.php")
     *   file_chunk_index – 0-based index within the current file
     *   data             – base64-encoded raw bytes
     *   checksum         – sha256 hex of the raw bytes (before base64)
     *   is_last          – true only on the very last chunk of the backup;
     *                      also carries total_chunks, total_bytes, backup_type
     *
     * @param string             $backup_type  "full" | "database" | "files"
     * @param string             $base_url     Stack2 base URL
     * @param string             $site_id      Stack2 Site ID
     * @param string             $api_key      Stack2 API key
     * @param Stack2_Http_Client $http_client  HTTP client used for pushing chunks
     * @return array
     */
    public function generate_and_push(
        string $backup_type,
        string $base_url,
        string $site_id,
        string $api_key,
        Stack2_Http_Client $http_client
    ): array {
        // Normalise backup type.
        $allowed = array('full', 'database', 'files');
        if ($backup_type === 'files_manifest') {
            $backup_type = 'files';
        }
        if (!in_array($backup_type, $allowed, true)) {
            $backup_type = 'full';
        }

        $backup_dir = $this->get_backup_dir();
        if ($backup_dir === null) {
            return array('success' => false, 'error' => 'Cannot create backup directory.');
        }

        try {
            $backup_id = 'bkp_' . bin2hex(random_bytes(16));
        } catch (Exception $e) {
            return array('success' => false, 'error' => 'Cannot generate a secure backup ID.');
        }

        // Allow longer execution for large-site backups.
        // phpcs:ignore WordPress.PHP.IniSet.Risky
        @set_time_limit(600);

        // Reset streaming state for this backup run.
        $this->next_chunk_index = 0;
        $this->total_bytes      = 0;
        $this->buffered_chunk   = null;
        $this->push_error       = null;

        // ── Database ─────────────────────────────────────────────────────────
        if ($backup_type === 'database' || $backup_type === 'full') {
            $sql_temp  = $backup_dir . '/' . $backup_id . '.sql.tmp';
            $db_result = $this->write_database_to_temp($sql_temp);
            if (!$db_result['success']) {
                update_option('stack2_last_backup_status', 'failed');
                update_option('stack2_last_backup_error', $db_result['error'] ?? 'Database backup failed.');
                return $db_result;
            }

            $this->stream_file_chunks(
                $sql_temp,
                'database/database.sql',
                $backup_id,
                $http_client,
                $base_url,
                $site_id,
                $api_key
            );

            @unlink($sql_temp);

            if ($this->push_error !== null) {
                $this->record_failure($backup_id, $this->push_error);
                return array('success' => false, 'error' => $this->push_error);
            }
        }

        // ── WordPress file tree ──────────────────────────────────────────────
        if (($backup_type === 'files' || $backup_type === 'full') && $this->push_error === null) {
            $wp_root = rtrim((string) ABSPATH, '/\\');

            // Compute the backup dir prefix once so we can exclude it reliably.
            $upload_info     = wp_upload_dir();
            $backup_dir_path = rtrim((string) $upload_info['basedir'], '/\\')
                . DIRECTORY_SEPARATOR . self::BACKUP_DIR_NAME;

            try {
                $iterator = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($wp_root, RecursiveDirectoryIterator::SKIP_DOTS),
                    RecursiveIteratorIterator::SELF_FIRST
                );

                foreach ($iterator as $item) {
                    if ($this->push_error !== null) {
                        break;
                    }

                    if (!($item instanceof SplFileInfo) || !$item->isFile() || !$item->isReadable()) {
                        continue;
                    }

                    $abs_path = $item->getPathname();

                    // Skip our backup temp directory to prevent recursive inclusion.
                    if (strpos($abs_path, $backup_dir_path) === 0) {
                        continue;
                    }

                    // Build a portable virtual path for Stack2 (forward slashes).
                    $relative = 'wordpress' . str_replace($wp_root, '', $abs_path);
                    $relative = str_replace(DIRECTORY_SEPARATOR, '/', $relative);
                    $relative = ltrim($relative, '/');

                    $this->stream_file_chunks(
                        $abs_path,
                        $relative,
                        $backup_id,
                        $http_client,
                        $base_url,
                        $site_id,
                        $api_key
                    );
                }
            } catch (Throwable $e) {
                $message = 'WordPress root backup error: ' . $e->getMessage();
                $this->logger->error($message);
                $this->record_failure($backup_id, $message);
                return array('success' => false, 'error' => $message);
            }
        }

        if ($this->push_error !== null) {
            $this->record_failure($backup_id, $this->push_error);
            return array('success' => false, 'error' => $this->push_error);
        }

        // ── Finalise: push the held-back chunk with summary metadata ─────────
        if ($this->buffered_chunk === null) {
            $message = 'Backup produced no data.';
            $this->record_failure($backup_id, $message);
            return array('success' => false, 'error' => $message);
        }

        $total_chunks                               = $this->next_chunk_index;
        $this->buffered_chunk['is_last']            = true;
        $this->buffered_chunk['total_chunks']       = $total_chunks;
        $this->buffered_chunk['total_bytes']        = $this->total_bytes;
        $this->buffered_chunk['backup_type']        = $backup_type;

        $final_result = $http_client->push_backup_chunk($base_url, $site_id, $api_key, $this->buffered_chunk);
        if (!$final_result['success']) {
            $error = $final_result['error'] ?? sprintf('Failed to push final chunk %d.', $this->buffered_chunk['chunk_index']);
            $this->record_failure($backup_id, $error);
            return array('success' => false, 'error' => $error);
        }

        update_option('stack2_last_backup_at', gmdate('c'));
        update_option('stack2_last_backup_status', 'pushed');
        update_option('stack2_last_backup_id', $backup_id);
        update_option('stack2_last_backup_size', $this->total_bytes);
        update_option('stack2_last_backup_total_chunks', $total_chunks);
        update_option('stack2_last_backup_checksum', '');
        update_option('stack2_last_backup_type', $backup_type);
        update_option('stack2_last_backup_error', '');

        $this->logger->info('Backup pushed successfully.', array(
            'backup_id'    => $backup_id,
            'type'         => $backup_type,
            'total_bytes'  => $this->total_bytes,
            'total_chunks' => $total_chunks,
        ));

        return array(
            'success'      => true,
            'backup_id'    => $backup_id,
            'total_chunks' => $total_chunks,
            'total_bytes'  => $this->total_bytes,
            'backup_type'  => $backup_type,
        );
    }

    /**
     * Return status for the given backup_id by reading from wp_options.
     * Returns null if the backup_id does not match the most recent backup.
     */
    public function get_status(string $backup_id): ?array
    {
        if (!preg_match(self::BACKUP_ID_PATTERN, $backup_id)) {
            return null;
        }

        $last_id = (string) get_option('stack2_last_backup_id', '');
        if ($last_id !== $backup_id) {
            return null;
        }

        return array(
            'backup_id'    => $last_id,
            'backup_type'  => (string) get_option('stack2_last_backup_type', ''),
            'status'       => (string) get_option('stack2_last_backup_status', 'unknown'),
            'total_bytes'  => (int)    get_option('stack2_last_backup_size', 0),
            'chunk_size'   => self::CHUNK_SIZE,
            'total_chunks' => (int)    get_option('stack2_last_backup_total_chunks', 0),
            'created_at'   => (string) get_option('stack2_last_backup_at', ''),
        );
    }

    /**
     * Delete any orphaned temp files for the given backup_id.
     * In stream mode the SQL temp file is deleted automatically after its
     * chunks are pushed; this is a safety net for interrupted runs.
     */
    public function cleanup(string $backup_id): bool
    {
        if (!preg_match(self::BACKUP_ID_PATTERN, $backup_id)) {
            return false;
        }

        $backup_dir = $this->get_backup_dir();
        if ($backup_dir === null) {
            return false;
        }

        $cleaned = true;
        $sql_tmp = $backup_dir . '/' . $backup_id . '.sql.tmp';
        if (file_exists($sql_tmp)) {
            $cleaned = unlink($sql_tmp);
        }

        if ($cleaned) {
            $this->logger->info('Backup temp files cleaned up.', array('backup_id' => $backup_id));
        }

        return $cleaned;
    }

    /**
     * Delete any orphaned SQL temp files that are older than EXPIRY_SECONDS.
     * These can accumulate if a push is interrupted before the temp file is removed.
     */
    public function cleanup_expired(): void
    {
        $backup_dir = $this->get_backup_dir();
        if ($backup_dir === null) {
            return;
        }

        foreach (glob($backup_dir . '/bkp_*.sql.tmp') ?: array() as $file) {
            $mtime = filemtime($file);
            if ($mtime !== false && (time() - $mtime) > self::EXPIRY_SECONDS) {
                @unlink($file);
                $this->logger->info('Expired orphan backup file cleaned up.', array('file' => basename($file)));
            }
        }
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    /**
     * Read a single file in CHUNK_SIZE pieces and enqueue each piece as a
     * backup chunk using the hold-back pattern.
     *
     * Hold-back pattern: we always keep the most-recently-built chunk in
     * $this->buffered_chunk without pushing it.  Before buffering the next
     * chunk we push the held-back one (without is_last).  This means the very
     * last chunk across all files remains in the buffer after this method
     * returns, allowing generate_and_push() to attach summary metadata and
     * set is_last=true before its final push.
     *
     * Unreadable files are logged and skipped (non-fatal) so a single
     * permission problem does not abort the whole backup.
     *
     * @param string             $abs_path    Absolute path to the file.
     * @param string             $virtual_path Virtual path in the backup (e.g. 'wordpress/wp-config.php').
     * @param string             $backup_id   Backup session identifier.
     * @param Stack2_Http_Client $http_client HTTP client.
     * @param string             $base_url    Stack2 base URL.
     * @param string             $site_id     Stack2 Site ID.
     * @param string             $api_key     Stack2 API key.
     */
    private function stream_file_chunks(
        string $abs_path,
        string $virtual_path,
        string $backup_id,
        Stack2_Http_Client $http_client,
        string $base_url,
        string $site_id,
        string $api_key
    ): void {
        if ($this->push_error !== null) {
            return;
        }

        $fh = @fopen($abs_path, 'rb');
        if ($fh === false) {
            $this->logger->error('Cannot open file for backup streaming.', array('path' => $abs_path));
            return; // Skip; non-fatal.
        }

        $file_chunk_index = 0;

        while (!feof($fh) && $this->push_error === null) {
            $raw = fread($fh, self::CHUNK_SIZE);
            if ($raw === false || strlen($raw) === 0) {
                break;
            }

            // Push the previously held chunk as a non-last chunk.
            if ($this->buffered_chunk !== null) {
                $result = $http_client->push_backup_chunk($base_url, $site_id, $api_key, $this->buffered_chunk);
                if (!$result['success']) {
                    $this->push_error   = $result['error'] ?? sprintf('Failed to push chunk %d.', $this->buffered_chunk['chunk_index']);
                    $this->buffered_chunk = null;
                    fclose($fh);
                    return;
                }
            }

            $this->total_bytes += strlen($raw);

            $this->buffered_chunk = array(
                'backup_id'        => $backup_id,
                'chunk_index'      => $this->next_chunk_index,
                'file_path'        => $virtual_path,
                'file_chunk_index' => $file_chunk_index,
                'data'             => base64_encode($raw),
                'checksum'         => hash('sha256', $raw),
                'is_last'          => false, // overridden on the very last chunk by generate_and_push()
            );

            $this->next_chunk_index++;
            $file_chunk_index++;
        }

        fclose($fh);
    }

    /**
     * Dump all WordPress database tables to a temporary SQL file.
     *
     * @param string $temp_path  Desired path for the temp file.
     * @return array{success:bool,temp_file?:string,error?:string}
     */
    private function write_database_to_temp(string $temp_path): array
    {
        global $wpdb;

        $fh = fopen($temp_path, 'wb');
        if ($fh === false) {
            return array('success' => false, 'error' => 'Cannot create temporary database dump file.');
        }

        fwrite($fh, "-- Stack2 WordPress Database Backup\n");
        fwrite($fh, "-- Generated: " . gmdate('c') . "\n");
        fwrite($fh, "-- WordPress Version: " . get_bloginfo('version') . "\n");
        fwrite($fh, "-- PHP Version: " . PHP_VERSION . "\n");
        fwrite($fh, "-- Site URL: " . home_url('/') . "\n\n");
        fwrite($fh, "SET FOREIGN_KEY_CHECKS=0;\n\n");

        $tables = $wpdb->get_col('SHOW TABLES');
        if (!is_array($tables)) {
            fclose($fh);
            @unlink($temp_path);
            return array('success' => false, 'error' => 'Failed to retrieve database tables.');
        }

        foreach ($tables as $table) {
            $safe_table = preg_replace('/[^a-zA-Z0-9_]/', '', (string) $table);
            if ($safe_table === '') {
                continue;
            }

            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $create = $wpdb->get_row("SHOW CREATE TABLE `{$safe_table}`", ARRAY_N);
            if (!is_array($create) || empty($create[1])) {
                continue;
            }

            fwrite($fh, "\n-- Table: {$safe_table}\n");
            fwrite($fh, "DROP TABLE IF EXISTS `{$safe_table}`;\n");
            fwrite($fh, $create[1] . ";\n\n");

            $offset = 0;
            $batch  = self::SQL_BATCH_SIZE;

            do {
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $rows = $wpdb->get_results(
                    $wpdb->prepare("SELECT * FROM `{$safe_table}` LIMIT %d OFFSET %d", $batch, $offset),
                    ARRAY_A
                );

                if (empty($rows)) {
                    break;
                }

                foreach ($rows as $row) {
                    $columns = implode(', ', array_map(static function ($col): string {
                        return '`' . str_replace('`', '``', (string) $col) . '`';
                    }, array_keys($row)));

                    $values = implode(', ', array_map(array($this, 'escape_sql_value'), $row));

                    fwrite($fh, "INSERT INTO `{$safe_table}` ({$columns}) VALUES ({$values});\n");
                }

                $offset += $batch;
            } while (count($rows) === $batch);
        }

        fwrite($fh, "\nSET FOREIGN_KEY_CHECKS=1;\n");
        fclose($fh);

        return array('success' => true, 'temp_file' => $temp_path);
    }

    /**
     * Escape a single SQL value for use in an INSERT statement.
     */
    private function escape_sql_value(mixed $value): string
    {
        if ($value === null) {
            return 'NULL';
        }

        return "'" . esc_sql((string) $value) . "'";
    }

    /**
     * Record a backup push failure in wp_options and the plugin log.
     */
    private function record_failure(string $backup_id, string $error): void
    {
        $this->logger->error('Backup push failed.', array('backup_id' => $backup_id, 'error' => $error));
        update_option('stack2_last_backup_status', 'failed');
        update_option('stack2_last_backup_error', $error);
    }

    /**
     * Return the path to the backups directory, creating it (with access
     * controls) if it does not yet exist.
     */
    private function get_backup_dir(): ?string
    {
        $upload_dir = wp_upload_dir();
        $dir        = trailingslashit((string) $upload_dir['basedir']) . self::BACKUP_DIR_NAME;

        if (!file_exists($dir)) {
            if (!wp_mkdir_p($dir)) {
                $this->logger->error('Cannot create backup directory.', array('dir' => $dir));
                return null;
            }

            // Deny direct HTTP access.
            file_put_contents($dir . '/.htaccess', "deny from all\nOptions -Indexes\n");
            file_put_contents($dir . '/index.php', "<?php // Silence is golden.\n");
        }

        return $dir;
    }
}
