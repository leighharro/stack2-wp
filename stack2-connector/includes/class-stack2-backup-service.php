<?php

if (!defined('ABSPATH')) {
    exit;
}

class Stack2_Backup_Service
{
    public const CHUNK_SIZE = 1048576; // 1 MB per chunk
    public const EXPIRY_SECONDS = 3600; // backups auto-expire after 1 hour
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
     * Initiate a new backup. Generates a compressed backup file and returns
     * metadata (backup_id, total_chunks, etc.) so the caller can fetch chunks.
     *
     * @param string $backup_type  "full" | "database" | "files_manifest"
     * @return array{success:bool,error?:string,backup_id?:string,total_chunks?:int,chunk_size?:int,file_size?:int,checksum?:string,expires_at?:string}
     */
    public function initiate_backup(string $backup_type = 'full'): array
    {
        $allowed_types = array('full', 'database', 'files_manifest');
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
        $backup_file = $backup_dir . '/' . $backup_id . '.gz';
        $meta_file = $backup_dir . '/' . $backup_id . '.json';

        // Allow longer execution for backup generation.
        // phpcs:ignore WordPress.PHP.IniSet.Risky
        @set_time_limit(300);

        $generate_result = $this->generate_backup($backup_file, $backup_type);
        if (!$generate_result['success']) {
            return $generate_result;
        }

        $file_size = (int) filesize($backup_file);
        if ($file_size === 0) {
            @unlink($backup_file);
            return array('success' => false, 'error' => 'Backup generation produced an empty file.');
        }
        $total_chunks = (int) ceil($file_size / self::CHUNK_SIZE);

        $meta = array(
            'backup_id' => $backup_id,
            'backup_type' => $backup_type,
            'status' => 'ready',
            'file' => $backup_file,
            'file_size' => $file_size,
            'chunk_size' => self::CHUNK_SIZE,
            'total_chunks' => max(1, $total_chunks),
            'checksum' => hash_file('sha256', $backup_file),
            'created_at' => gmdate('c'),
            'expires_at' => gmdate('c', time() + self::EXPIRY_SECONDS),
        );

        file_put_contents($meta_file, wp_json_encode($meta, JSON_UNESCAPED_SLASHES));

        update_option('stack2_last_backup_at', $meta['created_at']);
        update_option('stack2_last_backup_status', 'ready');
        update_option('stack2_last_backup_id', $backup_id);
        update_option('stack2_last_backup_size', $file_size);
        update_option('stack2_last_backup_error', '');

        $this->logger->info('Backup created.', array(
            'backup_id' => $backup_id,
            'type' => $backup_type,
            'size' => $file_size,
            'total_chunks' => $meta['total_chunks'],
        ));

        return array(
            'success' => true,
            'backup_id' => $backup_id,
            'total_chunks' => $meta['total_chunks'],
            'chunk_size' => self::CHUNK_SIZE,
            'file_size' => $file_size,
            'checksum' => $meta['checksum'],
            'expires_at' => $meta['expires_at'],
        );
    }

    /**
     * Return status metadata for an existing backup.
     */
    public function get_status(string $backup_id): ?array
    {
        $meta = $this->load_meta($backup_id);
        if ($meta === null) {
            return null;
        }

        return array(
            'backup_id' => $meta['backup_id'],
            'backup_type' => $meta['backup_type'],
            'status' => $meta['status'],
            'file_size' => $meta['file_size'],
            'chunk_size' => $meta['chunk_size'],
            'total_chunks' => $meta['total_chunks'],
            'checksum' => $meta['checksum'],
            'created_at' => $meta['created_at'],
            'expires_at' => $meta['expires_at'],
        );
    }

    /**
     * Read a specific chunk from the backup file and return it as base64.
     * Returns null if the backup_id or chunk_index is invalid.
     */
    public function get_chunk(string $backup_id, int $chunk_index): ?array
    {
        $meta = $this->load_meta($backup_id);
        if ($meta === null) {
            return null;
        }

        if ($chunk_index < 0 || $chunk_index >= $meta['total_chunks']) {
            return null;
        }

        $backup_file = $meta['file'];
        if (!file_exists($backup_file)) {
            return null;
        }

        $offset = $chunk_index * $meta['chunk_size'];
        $fp = fopen($backup_file, 'rb');
        if ($fp === false) {
            return null;
        }

        fseek($fp, $offset);
        $data = fread($fp, $meta['chunk_size']);
        fclose($fp);

        if ($data === false) {
            return null;
        }

        return array(
            'backup_id' => $backup_id,
            'chunk_index' => $chunk_index,
            'total_chunks' => $meta['total_chunks'],
            'data' => base64_encode($data),
            'checksum' => hash('sha256', $data),
            'is_last' => ($chunk_index === $meta['total_chunks'] - 1),
        );
    }

    /**
     * Delete backup files for the given backup_id.
     */
    public function cleanup(string $backup_id): bool
    {
        $backup_dir = $this->get_backup_dir();
        if ($backup_dir === null) {
            return false;
        }

        if (!preg_match(self::BACKUP_ID_PATTERN, $backup_id)) {
            return false;
        }

        $backup_file = $backup_dir . '/' . $backup_id . '.gz';
        $meta_file = $backup_dir . '/' . $backup_id . '.json';

        $cleaned = true;
        if (file_exists($backup_file)) {
            if (!unlink($backup_file)) {
                $cleaned = false;
            }
        }
        if (file_exists($meta_file)) {
            if (!unlink($meta_file)) {
                $cleaned = false;
            }
        }

        $this->logger->info('Backup cleaned up.', array('backup_id' => $backup_id));

        return $cleaned;
    }

    /**
     * Remove all backup files whose expiry timestamp has passed.
     */
    public function cleanup_expired(): void
    {
        $backup_dir = $this->get_backup_dir();
        if ($backup_dir === null) {
            return;
        }

        $meta_files = glob($backup_dir . '/bkp_*.json');
        if (!is_array($meta_files)) {
            return;
        }

        foreach ($meta_files as $meta_file) {
            $raw = file_get_contents($meta_file);
            if ($raw === false) {
                continue;
            }
            $meta = json_decode($raw, true);
            if (!is_array($meta)) {
                continue;
            }

            $expires_at = isset($meta['expires_at']) ? strtotime((string) $meta['expires_at']) : false;
            if ($expires_at !== false && time() > $expires_at) {
                $backup_id = $meta['backup_id'] ?? '';
                if ($backup_id !== '') {
                    $this->cleanup($backup_id);
                }
            }
        }
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    private function generate_backup(string $backup_file, string $backup_type): array
    {
        $gz = gzopen($backup_file, 'wb9');
        if ($gz === false) {
            return array('success' => false, 'error' => 'Cannot create backup file.');
        }

        try {
            if ($backup_type === 'database' || $backup_type === 'full') {
                $db_result = $this->write_database_backup($gz);
                if (!$db_result['success']) {
                    gzclose($gz);
                    @unlink($backup_file);

                    update_option('stack2_last_backup_status', 'failed');
                    update_option('stack2_last_backup_error', $db_result['error'] ?? 'Database backup failed.');

                    return $db_result;
                }
            }

            if ($backup_type === 'files_manifest' || $backup_type === 'full') {
                $this->write_files_manifest($gz);
            }
        } catch (Throwable $e) {
            gzclose($gz);
            @unlink($backup_file);

            $message = 'Backup generation failed: ' . $e->getMessage();
            $this->logger->error($message, array('backup_file' => $backup_file));

            update_option('stack2_last_backup_status', 'failed');
            update_option('stack2_last_backup_error', $message);

            return array('success' => false, 'error' => $message);
        }

        gzclose($gz);

        return array('success' => true);
    }

    /**
     * Write a full SQL dump of all WordPress database tables to the gz handle.
     *
     * @param resource $gz
     */
    private function write_database_backup($gz): array
    {
        global $wpdb;

        gzwrite($gz, "-- Stack2 WordPress Database Backup\n");
        gzwrite($gz, "-- Generated: " . gmdate('c') . "\n");
        gzwrite($gz, "-- WordPress Version: " . get_bloginfo('version') . "\n");
        gzwrite($gz, "-- PHP Version: " . PHP_VERSION . "\n");
        gzwrite($gz, "-- Site URL: " . home_url('/') . "\n\n");
        gzwrite($gz, "SET FOREIGN_KEY_CHECKS=0;\n\n");

        $tables = $wpdb->get_col('SHOW TABLES');
        if (!is_array($tables)) {
            return array('success' => false, 'error' => 'Failed to retrieve database tables.');
        }

        foreach ($tables as $table) {
            // Sanitise table name – comes from SHOW TABLES, but we validate anyway.
            $safe_table = preg_replace('/[^a-zA-Z0-9_]/', '', (string) $table);
            if ($safe_table === '') {
                continue;
            }

            // CREATE TABLE statement.
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $create = $wpdb->get_row("SHOW CREATE TABLE `{$safe_table}`", ARRAY_N);
            if (!is_array($create) || empty($create[1])) {
                continue;
            }

            gzwrite($gz, "\n-- Table: {$safe_table}\n");
            gzwrite($gz, "DROP TABLE IF EXISTS `{$safe_table}`;\n");
            gzwrite($gz, $create[1] . ";\n\n");

            // Row data in batches to keep memory usage low.
            $offset = 0;
            $batch = 200;

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

                    gzwrite($gz, "INSERT INTO `{$safe_table}` ({$columns}) VALUES ({$values});\n");
                }

                $offset += $batch;
            } while (count($rows) === $batch);
        }

        gzwrite($gz, "\nSET FOREIGN_KEY_CHECKS=1;\n");

        return array('success' => true);
    }

    /**
     * Write a plain-text manifest of all files inside wp-content/uploads.
     *
     * @param resource $gz
     */
    private function write_files_manifest($gz): void
    {
        $upload_dir = wp_upload_dir();
        $base_dir = trailingslashit((string) $upload_dir['basedir']);

        if (!is_dir($base_dir)) {
            return;
        }

        gzwrite($gz, "\n-- Files Manifest (wp-content/uploads)\n");
        gzwrite($gz, "-- Base Directory: {$base_dir}\n");
        gzwrite($gz, "-- Generated: " . gmdate('c') . "\n\n");

        try {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($base_dir, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::SELF_FIRST
            );

            foreach ($iterator as $file) {
                if (!($file instanceof SplFileInfo) || !$file->isFile()) {
                    continue;
                }

                $path = $file->getPathname();

                // Skip files inside our own backup directory.
                if (strpos($path, DIRECTORY_SEPARATOR . self::BACKUP_DIR_NAME . DIRECTORY_SEPARATOR) !== false) {
                    continue;
                }

                $relative = ltrim(str_replace($base_dir, '', $path), '/\\');
                $size = $file->getSize();
                $mtime = $file->getMTime();

                gzwrite($gz, "FILE: {$relative} | SIZE: {$size} | MTIME: {$mtime}\n");
            }
        } catch (Throwable $e) {
            $this->logger->error('Files manifest generation error.', array('error' => $e->getMessage()));
            gzwrite($gz, "-- WARNING: Manifest incomplete due to error: " . $e->getMessage() . "\n");
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

    /**
     * Load and return a backup's metadata, or null if not found / invalid.
     */
    private function load_meta(string $backup_id): ?array
    {
        if (!preg_match(self::BACKUP_ID_PATTERN, $backup_id)) {
            return null;
        }

        $backup_dir = $this->get_backup_dir();
        if ($backup_dir === null) {
            return null;
        }

        $meta_file = $backup_dir . '/' . $backup_id . '.json';
        if (!file_exists($meta_file)) {
            return null;
        }

        $raw = file_get_contents($meta_file);
        if ($raw === false) {
            return null;
        }

        $meta = json_decode($raw, true);
        return is_array($meta) ? $meta : null;
    }
}
