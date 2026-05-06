<?php

if (!defined('ABSPATH')) {
    exit;
}

class Stack2_Backup_Service
{
    public const CHUNK_SIZE = 10485760; // 10 MB per chunk
    public const EXPIRY_SECONDS = 3600; // orphan files cleaned up after 1 hour
    public const SQL_BATCH_SIZE = 1000; // rows per SELECT batch when dumping tables
    /** Raw regex fragment (no anchors/delimiters) for backup ID validation. */
    public const BACKUP_ID_RAW_REGEX = 'bkp_[a-f0-9]{32}';
    private const BACKUP_DIR_NAME = 'stack2-backups';
    private const BACKUP_ID_PATTERN = '/^bkp_[a-f0-9]{32}$/';

    private Stack2_Logger $logger;

    public function __construct(Stack2_Logger $logger)
    {
        $this->logger = $logger;
    }

    /**
     * Generate a compressed backup and push each chunk to the Stack2 API.
     * The temp file is deleted from the server as soon as all chunks are pushed.
     *
     * @param string             $backup_type  "full" | "database" | "files"
     * @param string             $base_url     Stack2 base URL (e.g. https://app.stack2.au)
     * @param string             $site_id      Stack2 Site ID
     * @param string             $api_key      Stack2 API key
     * @param Stack2_Http_Client $http_client  HTTP client used for pushing chunks
     * @return array{success:bool,error?:string,backup_id?:string,total_chunks?:int,file_size?:int,checksum?:string,backup_type?:string}
     */
    public function generate_and_push(
        string $backup_type,
        string $base_url,
        string $site_id,
        string $api_key,
        Stack2_Http_Client $http_client
    ): array {
        $allowed_types = array('full', 'database', 'files');
        // Treat legacy 'files_manifest' as 'files'.
        if ($backup_type === 'files_manifest') {
            $backup_type = 'files';
        }
        if (!in_array($backup_type, $allowed_types, true)) {
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

        $backup_file = $backup_dir . '/' . $backup_id . '.zip';

        // Allow longer execution for large-site backup generation.
        // phpcs:ignore WordPress.PHP.IniSet.Risky
        @set_time_limit(600);

        $generate_result = $this->generate_backup($backup_file, $backup_type);
        if (!$generate_result['success']) {
            return $generate_result;
        }

        $file_size = (int) filesize($backup_file);
        if ($file_size === 0) {
            @unlink($backup_file);
            return array('success' => false, 'error' => 'Backup generation produced an empty file.');
        }

        $file_checksum = hash_file('sha256', $backup_file);
        $total_chunks = max(1, (int) ceil($file_size / self::CHUNK_SIZE));

        // Open file and push each chunk to Stack2 as it is read.
        $fp = fopen($backup_file, 'rb');
        if ($fp === false) {
            @unlink($backup_file);
            return array('success' => false, 'error' => 'Failed to open backup file for reading. Check file permissions.');
        }

        $push_error = null;
        for ($i = 0; $i < $total_chunks; $i++) {
            $chunk_bytes = fread($fp, self::CHUNK_SIZE);
            if ($chunk_bytes === false) {
                $push_error = sprintf('Failed to read chunk %d from backup file.', $i);
                break;
            }

            $is_last = ($i === $total_chunks - 1);
            $payload = array(
                'backup_id' => $backup_id,
                'chunk_index' => $i,
                'data' => base64_encode($chunk_bytes),
                'checksum' => hash('sha256', $chunk_bytes),
                'is_last' => $is_last,
            );

            // Include summary metadata on the final chunk so Stack2 can verify
            // the fully reassembled file and close the upload.
            if ($is_last) {
                $payload['total_chunks'] = $total_chunks;
                $payload['file_size'] = $file_size;
                $payload['file_checksum'] = $file_checksum;
                $payload['backup_type'] = $backup_type;
            }

            $result = $http_client->push_backup_chunk($base_url, $site_id, $api_key, $payload);
            if (!$result['success']) {
                $push_error = $result['error'] ?? sprintf('Failed to push chunk %d.', $i);
                break;
            }
        }

        fclose($fp);
        @unlink($backup_file); // Always remove the temp file from this server.

        if ($push_error !== null) {
            $this->logger->error('Backup push failed.', array('backup_id' => $backup_id, 'error' => $push_error));
            update_option('stack2_last_backup_status', 'failed');
            update_option('stack2_last_backup_error', $push_error);
            return array('success' => false, 'error' => $push_error);
        }

        update_option('stack2_last_backup_at', gmdate('c'));
        update_option('stack2_last_backup_status', 'pushed');
        update_option('stack2_last_backup_id', $backup_id);
        update_option('stack2_last_backup_size', $file_size);
        update_option('stack2_last_backup_total_chunks', $total_chunks);
        update_option('stack2_last_backup_checksum', $file_checksum);
        update_option('stack2_last_backup_type', $backup_type);
        update_option('stack2_last_backup_error', '');

        $this->logger->info('Backup pushed successfully.', array(
            'backup_id' => $backup_id,
            'type' => $backup_type,
            'size' => $file_size,
            'total_chunks' => $total_chunks,
        ));

        return array(
            'success' => true,
            'backup_id' => $backup_id,
            'total_chunks' => $total_chunks,
            'file_size' => $file_size,
            'checksum' => $file_checksum,
            'backup_type' => $backup_type,
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
            'backup_id' => $last_id,
            'backup_type' => (string) get_option('stack2_last_backup_type', ''),
            'status' => (string) get_option('stack2_last_backup_status', 'unknown'),
            'file_size' => (int) get_option('stack2_last_backup_size', 0),
            'chunk_size' => self::CHUNK_SIZE,
            'total_chunks' => (int) get_option('stack2_last_backup_total_chunks', 0),
            'checksum' => (string) get_option('stack2_last_backup_checksum', ''),
            'created_at' => (string) get_option('stack2_last_backup_at', ''),
        );
    }

    /**
     * Delete any orphaned backup file for the given backup_id.
     * In push mode the file is deleted automatically after push, so this
     * is a safety net for failed pushes.
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

        $backup_file = $backup_dir . '/' . $backup_id . '.zip';
        if (file_exists($backup_file)) {
            $result = unlink($backup_file);
            $this->logger->info('Backup file cleaned up.', array('backup_id' => $backup_id));
            return $result;
        }

        // File does not exist – already cleaned up, treat as success.
        return true;
    }

    /**
     * Delete any orphaned .zip files that are older than EXPIRY_SECONDS.
     * These can accumulate if a push fails before the temp file is removed.
     */
    public function cleanup_expired(): void
    {
        $backup_dir = $this->get_backup_dir();
        if ($backup_dir === null) {
            return;
        }

        $gz_files = glob($backup_dir . '/bkp_*.zip');
        if (!is_array($gz_files)) {
            return;
        }

        foreach ($gz_files as $gz_file) {
            $mtime = filemtime($gz_file);
            if ($mtime !== false && (time() - $mtime) > self::EXPIRY_SECONDS) {
                @unlink($gz_file);
                $this->logger->info('Expired orphan backup file cleaned up.', array('file' => basename($gz_file)));
            }
        }
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Build a ZIP archive containing the requested backup content.
     *
     * Archive layout:
     *   database/database.sql   – full SQL dump  (types: database, full)
     *   wordpress/              – entire WordPress root recursively  (types: files, full)
     *                             includes wp-admin/, wp-includes/, wp-content/,
     *                             wp-config.php, .htaccess, index.php, and any
     *                             other files placed in the WordPress root directory.
     *
     * @param string $backup_file  Absolute path to write the zip archive to.
     * @param string $backup_type  "full" | "database" | "files"
     */
    private function generate_backup(string $backup_file, string $backup_type): array
    {
        if (!class_exists('ZipArchive')) {
            return array('success' => false, 'error' => 'PHP ZipArchive extension is not available on this server.');
        }

        $zip = new ZipArchive();
        if ($zip->open($backup_file, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            return array('success' => false, 'error' => 'Cannot create backup archive.');
        }

        // ZipArchive::addFile() only registers paths; actual file reading happens
        // at close().  Any temp files must therefore exist until after close().
        $temp_files = array();

        try {
            if ($backup_type === 'database' || $backup_type === 'full') {
                $db_result = $this->write_database_to_temp($backup_file . '.sql.tmp');
                if (!$db_result['success']) {
                    $zip->close();
                    @unlink($backup_file);
                    update_option('stack2_last_backup_status', 'failed');
                    update_option('stack2_last_backup_error', $db_result['error'] ?? 'Database backup failed.');
                    return $db_result;
                }
                $temp_files[] = $db_result['temp_file'];
                $zip->addFile($db_result['temp_file'], 'database/database.sql');
            }

            if ($backup_type === 'files' || $backup_type === 'full') {
                $this->add_wordpress_root_to_zip($zip, $backup_file);
            }
        } catch (Throwable $e) {
            $zip->close();
            @unlink($backup_file);
            foreach ($temp_files as $tf) {
                @unlink($tf);
            }

            $message = 'Backup generation failed: ' . $e->getMessage();
            $this->logger->error($message, array('backup_file' => $backup_file));
            update_option('stack2_last_backup_status', 'failed');
            update_option('stack2_last_backup_error', $message);
            return array('success' => false, 'error' => $message);
        }

        // close() is when ZipArchive reads all registered files and writes the archive.
        $zip->close();

        foreach ($temp_files as $tf) {
            @unlink($tf);
        }

        return array('success' => true);
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
            $batch = self::SQL_BATCH_SIZE;

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
     * Add all files from the WordPress root directory (ABSPATH) to the ZIP archive.
     *
     * Every file found under ABSPATH is stored under wordpress/ in the archive,
     * preserving the full directory tree so the site can be restored intact.
     *
     * Exclusions:
     *  - Our own backup directory (prevents recursive self-inclusion).
     *  - The ZIP archive file currently being written.
     *
     * @param ZipArchive $zip         Open archive to add files into.
     * @param string     $backup_file Absolute path of the ZIP file being created (excluded).
     */
    private function add_wordpress_root_to_zip(ZipArchive $zip, string $backup_file): void
    {
        $wp_root = rtrim((string) ABSPATH, '/\\');

        if (!is_dir($wp_root)) {
            return;
        }

        // Compute the absolute backup dir path so we can reliably exclude it.
        $upload_dir = wp_upload_dir();
        $backup_dir_path = rtrim((string) $upload_dir['basedir'], '/\\')
            . DIRECTORY_SEPARATOR . self::BACKUP_DIR_NAME;

        // Resolve the backup ZIP path once so the comparison is symlink-safe.
        $backup_file_real = realpath($backup_file) ?: $backup_file;

        try {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($wp_root, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::SELF_FIRST
            );

            foreach ($iterator as $item) {
                if (!($item instanceof SplFileInfo)) {
                    continue;
                }

                $real_path = $item->getPathname();

                // Skip the backup directory and everything inside it.
                if (strpos($real_path, $backup_dir_path) === 0) {
                    continue;
                }

                // Skip the ZIP file currently being written.
                $real_path_resolved = realpath($real_path) ?: $real_path;
                if ($real_path_resolved === $backup_file_real) {
                    continue;
                }

                // Build a portable relative path under wordpress/ in the archive.
                $relative = 'wordpress' . str_replace($wp_root, '', $real_path);
                $relative = str_replace(DIRECTORY_SEPARATOR, '/', $relative);
                $relative = ltrim($relative, '/');

                if ($item->isDir()) {
                    $zip->addEmptyDir($relative);
                } elseif ($item->isFile() && $item->isReadable()) {
                    $zip->addFile($real_path, $relative);
                }
            }
        } catch (Throwable $e) {
            $this->logger->error('WordPress root backup error.', array('error' => $e->getMessage()));
        }
    }

    /**
     * Escape a single SQL value for use in an INSERT statement.
     * Uses WordPress's esc_sql() for string escaping.
     */
    private function escape_sql_value(mixed $value): string
    {
        if ($value === null) {
            return 'NULL';
        }

        return "'" . esc_sql((string) $value) . "'";
    }

    /**
     * Return the path to the backups directory, creating it if necessary.
     */
    private function get_backup_dir(): ?string
    {
        $upload_dir = wp_upload_dir();
        $dir = trailingslashit((string) $upload_dir['basedir']) . self::BACKUP_DIR_NAME;

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
