<?php

if (!defined('ABSPATH')) {
    exit;
}

class Stack2_Backup_Manager
{
    private Stack2_Logger $logger;
    private Stack2_Backup_Compressor $compressor;
    private Stack2_Database_Dumper $database_dumper;
    private Stack2_Backup_Manifest $manifest_builder;
    private Stack2_Backup_Cleaner $cleaner;

    public function __construct(
        Stack2_Logger $logger,
        Stack2_Backup_Compressor $compressor,
        Stack2_Database_Dumper $database_dumper,
        Stack2_Backup_Manifest $manifest_builder,
        Stack2_Backup_Cleaner $cleaner
    ) {
        $this->logger = $logger;
        $this->compressor = $compressor;
        $this->database_dumper = $database_dumper;
        $this->manifest_builder = $manifest_builder;
        $this->cleaner = $cleaner;
    }

    public function initiate_backup(string $backup_id, bool $include_files, bool $include_database, string $requested_at): array
    {
        if (!$include_files && !$include_database) {
            throw new InvalidArgumentException('Missing include_files and include_database both false');
        }

        $backup_id = $this->normalize_backup_id($backup_id);
        $job_id = 'backup_' . substr(str_replace('-', '', $backup_id), 0, 8) . '_' . time();

        $temp_dir = trailingslashit($this->get_base_backup_dir()) . $job_id;
        wp_mkdir_p($temp_dir);

        $file_estimate = $include_files
            ? $this->compressor->estimate_wp_content(ABSPATH, true)
            : array('files_count' => 0, 'bytes_total' => 0, 'files' => array());

        $database_info = $include_database
            ? $this->database_dumper->get_database_info()
            : array(
                'host' => '',
                'port' => 3306,
                'name' => '',
                'charset' => '',
                'collation' => '',
                'size_bytes' => 0,
                'tables_count' => 0,
            );

        $manifest = $this->manifest_builder->build_manifest(
            $backup_id,
            $job_id,
            $include_files,
            $include_database,
            $database_info,
            $file_estimate
        );
        $manifest['backup_started_at'] = $requested_at !== '' ? $requested_at : gmdate('c');

        $job = array(
            'job_id' => $job_id,
            'backup_id' => $backup_id,
            'status' => 'transfer_pending',
            'cancel_requested' => false,
            'include_files' => $include_files,
            'include_database' => $include_database,
            'started_at' => gmdate('c'),
            'updated_at' => gmdate('c'),
            'completed_at' => null,
            'temp_directory' => $temp_dir,
            'manifest' => $manifest,
            'database_dump_file' => null,
            'files_archive_file' => null,
            'files_size_bytes' => 0,
            'database_size_bytes' => 0,
            'error' => null,
            'progress' => array(
                'phase' => 'transfer_pending',
                'files_processed' => (int) ($file_estimate['files_count'] ?? 0),
                'files_total' => (int) ($file_estimate['files_count'] ?? 0),
                'database_rows_processed' => 0,
                'database_rows_total' => 0,
                'bytes_processed' => (int) ($file_estimate['bytes_total'] ?? 0),
                'bytes_total' => (int) ($file_estimate['bytes_total'] ?? 0),
                'percent' => 100,
                'current_file' => null,
            ),
        );

        $this->logger->info('Backup initiated in stateless mode.', array('backup_id' => $backup_id, 'job_id' => $job_id));

        return $job;
    }

    public function get_backup_manifest_file(string $job_id, string $relative_path): ?array
    {
        $relative_path = $this->sanitize_relative_path($relative_path);
        if ($relative_path === '') {
            return null;
        }

        $absolute_path = trailingslashit(ABSPATH) . $relative_path;
        $normalized_absolute = wp_normalize_path($absolute_path);
        $normalized_root = trailingslashit(wp_normalize_path(ABSPATH));

        if (strpos($normalized_absolute, $normalized_root) !== 0) {
            return null;
        }

        if (!is_file($absolute_path) || !is_readable($absolute_path)) {
            return null;
        }

        return array(
            'path' => $absolute_path,
            'relative_path' => $relative_path,
            'content_type' => 'application/octet-stream',
            'filename' => basename($absolute_path),
            'size' => (int) filesize($absolute_path),
            'checksum' => hash_file('sha256', $absolute_path),
        );
    }

    public function get_backup_database_table_file(string $job_id, string $table_name): ?array
    {
        $table_name = $this->sanitize_table_name($table_name);
        if ($table_name === '') {
            return null;
        }

        $temp_dir = trailingslashit($this->get_base_backup_dir()) . $job_id;
        wp_mkdir_p($temp_dir);

        $dump = $this->database_dumper->dump_database_table($temp_dir, $table_name);
        $file = (string) ($dump['file'] ?? '');
        if ($file === '' || !is_file($file) || !is_readable($file)) {
            return null;
        }

        return array(
            'path' => $file,
            'table' => $table_name,
            'content_type' => 'application/gzip',
            'filename' => basename($file),
            'size' => (int) filesize($file),
            'checksum' => hash_file('sha256', $file),
        );
    }

    public function cleanup_backup(string $job_id): ?array
    {
        $temp_dir = trailingslashit($this->get_base_backup_dir()) . sanitize_key($job_id);
        $freed_mb = $this->cleaner->cleanup_backup($temp_dir);

        return array(
            'job_id' => $job_id,
            'temp_directory' => $temp_dir,
            'freed_space_mb' => $freed_mb,
        );
    }

    public function cleanup_abandoned(): int
    {
        $base_dir = $this->get_base_backup_dir();
        return $this->cleaner->cleanup_abandoned($base_dir, 24 * HOUR_IN_SECONDS);
    }

    // Compatibility no-ops for deprecated stateful endpoints.
    public function get_backup_status(string $job_id): ?array
    {
        return null;
    }

    public function cancel_backup(string $job_id): ?array
    {
        return null;
    }

    public function list_backups(?string $status = null, int $limit = 50): array
    {
        return array();
    }

    public function process_backup(string $job_id): void
    {
    }

    private function normalize_backup_id(string $backup_id): string
    {
        $trimmed = trim($backup_id);
        if ($trimmed === '') {
            return wp_generate_uuid4();
        }

        if (wp_is_uuid($trimmed)) {
            return strtolower($trimmed);
        }

        $hex = md5($trimmed);
        return substr($hex, 0, 8)
            . '-'
            . substr($hex, 8, 4)
            . '-'
            . substr($hex, 12, 4)
            . '-'
            . substr($hex, 16, 4)
            . '-'
            . substr($hex, 20, 12);
    }

    private function get_base_backup_dir(): string
    {
        return trailingslashit(WP_CONTENT_DIR) . '.stack2-backup';
    }

    private function sanitize_relative_path(string $relative_path): string
    {
        $path = ltrim(trim($relative_path), '/');
        if ($path === '' || strpos($path, "\0") !== false) {
            return '';
        }

        $path = wp_normalize_path($path);
        if (strpos($path, '../') !== false || $path === '..') {
            return '';
        }

        return $path;
    }

    private function sanitize_table_name(string $table_name): string
    {
        $name = trim($table_name);
        if ($name === '' || strpos($name, "\0") !== false) {
            return '';
        }

        if (!preg_match('/^[A-Za-z0-9_\$]+$/', $name)) {
            return '';
        }

        return $name;
    }
}
