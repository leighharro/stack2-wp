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
    private Stack2_Backup_File_Scanner $file_scanner;
    private string $wordpress_root;
    private string $backup_base_dir;

    public function __construct(
        Stack2_Logger $logger,
        Stack2_Backup_Compressor $compressor,
        Stack2_Database_Dumper $database_dumper,
        Stack2_Backup_Manifest $manifest_builder,
        Stack2_Backup_Cleaner $cleaner,
        ?string $wordpress_root = null,
        ?string $backup_base_dir = null,
        ?Stack2_Backup_File_Scanner $file_scanner = null
    ) {
        $this->logger = $logger;
        $this->compressor = $compressor;
        $this->database_dumper = $database_dumper;
        $this->manifest_builder = $manifest_builder;
        $this->cleaner = $cleaner;
        $this->wordpress_root = $wordpress_root !== null && $wordpress_root !== ''
            ? $wordpress_root
            : (defined('ABSPATH') ? (string) ABSPATH : '');
        $this->backup_base_dir = $backup_base_dir !== null && $backup_base_dir !== ''
            ? $backup_base_dir
            : '';
        $this->file_scanner = $file_scanner ?? new Stack2_Backup_File_Scanner(
            $this->compressor,
            $this->wordpress_root
        );
    }

    /**
     * Performs the cheap, bounded-time setup for a backup (id normalization,
     * temp dir, database metadata query) without walking/hashing any files.
     * File inventory is driven by Platform via GET .../files/scan and
     * POST .../files/stats.
     */
    public function prepare_backup(string $backup_id, bool $include_files, bool $include_database, string $requested_at, string $requested_job_id = ''): array
    {
        if (!$include_files && !$include_database) {
            throw new InvalidArgumentException('Missing include_files and include_database both false');
        }

        $backup_id = $this->normalize_backup_id($backup_id);
        $job_id = $this->normalize_job_id($requested_job_id, $backup_id);

        $temp_dir = trailingslashit($this->get_base_backup_dir()) . $job_id;
        wp_mkdir_p($temp_dir);

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
                'tables' => array(),
            );

        $backup_started_at = $requested_at !== '' ? $requested_at : gmdate('c');

        $this->logger->info('Backup initiated in agent inventory mode.', array('backup_id' => $backup_id, 'job_id' => $job_id));

        return array(
            'backup_id' => $backup_id,
            'job_id' => $job_id,
            'temp_dir' => $temp_dir,
            'database_info' => $database_info,
            'backup_started_at' => $backup_started_at,
            'include_files' => $include_files,
            'include_database' => $include_database,
            'manifest_mode' => 'agent',
            'inventory_limits' => Stack2_Backup_File_Scanner::inventory_limits(),
        );
    }

    public function build_initiate_manifest(array $prepared): array
    {
        $include_files = !empty($prepared['include_files']);
        $include_database = !empty($prepared['include_database']);

        return $this->manifest_builder->build_manifest(
            (string) $prepared['backup_id'],
            (string) $prepared['job_id'],
            $include_files,
            $include_database,
            is_array($prepared['database_info'] ?? null) ? $prepared['database_info'] : array(),
            array(
                'files' => array(),
                'files_count' => 0,
            ),
            array(
                'backup_started_at' => (string) ($prepared['backup_started_at'] ?? ''),
                'manifest_mode' => 'agent',
                'manifest_complete' => true,
            )
        );
    }

    /**
     * @return array{entries: array<int, array>, next_cursor: ?string, has_more: bool, scanned: int}
     */
    public function scan_files(string $job_id, ?string $cursor, $limit, bool $include_sha256 = false, bool $include_dirs = false): array
    {
        $this->require_job($job_id);

        return $this->file_scanner->scan($cursor, $limit, $include_sha256, $include_dirs);
    }

    /**
     * @param array<int, mixed> $paths
     * @return array{stats: array<int, array>, missing: array<int, string>, failed: array<int, array{path: string, error: string}>}
     */
    public function stat_files(string $job_id, array $paths, bool $include_sha256 = true): array
    {
        $this->require_job($job_id);

        return $this->file_scanner->stats($paths, $include_sha256);
    }

    /**
     * Walks and hashes every file in the WordPress install, invoking $on_file
     * per file. Kept for callers that still need a full in-process walk.
     */
    public function stream_file_manifest(bool $include_files, callable $on_file): array
    {
        if (!$include_files) {
            return array('files_count' => 0, 'bytes_total' => 0);
        }

        return $this->compressor->walk_wp_content_with_checksums($this->wordpress_root, $on_file);
    }

    public function get_backup_manifest_file(string $job_id, string $relative_path): ?array
    {
        $relative_path = $this->sanitize_relative_path($relative_path);
        if ($relative_path === '') {
            return null;
        }

        $absolute_path = trailingslashit($this->wordpress_root) . $relative_path;
        $normalized_absolute = wp_normalize_path($absolute_path);
        $normalized_root = trailingslashit(wp_normalize_path($this->wordpress_root));

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
            'checksum' => $this->get_file_checksum($absolute_path),
        );
    }

    public function get_backup_manifest_file_metadata(string $job_id, string $relative_path): ?array
    {
        $relative_path = $this->sanitize_relative_path($relative_path);
        if ($relative_path === '') {
            return null;
        }

        $absolute_path = trailingslashit($this->wordpress_root) . $relative_path;
        $normalized_absolute = wp_normalize_path($absolute_path);
        $normalized_root = trailingslashit(wp_normalize_path($this->wordpress_root));

        if (strpos($normalized_absolute, $normalized_root) !== 0) {
            return null;
        }

        if (!is_file($absolute_path) || !is_readable($absolute_path)) {
            return null;
        }

        return array(
            'path' => $relative_path,
            'sha256' => strtolower($this->get_file_checksum($absolute_path)),
            'size' => (int) filesize($absolute_path),
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

    public function stream_database_table_to_output(string $job_id, string $table_name): void
    {
        $table_name = $this->sanitize_table_name($table_name);
        if ($table_name === '') {
            throw new RuntimeException('Invalid table name.');
        }

        $temp_dir = trailingslashit($this->get_base_backup_dir()) . $job_id;
        wp_mkdir_p($temp_dir);

        $this->database_dumper->stream_table_to_output_and_cache($temp_dir, $table_name);
    }

    public function get_cached_database_table_file(string $job_id, string $table_name): ?array
    {
        $table_name = $this->sanitize_table_name($table_name);
        if ($table_name === '') {
            return null;
        }

        $temp_dir = trailingslashit($this->get_base_backup_dir()) . $job_id;
        $dump = $this->database_dumper->get_table_dump_if_exists($temp_dir, $table_name);
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

    public function database_table_exists(string $table_name): bool
    {
        $table_name = $this->sanitize_table_name($table_name);
        if ($table_name === '') {
            return false;
        }

        return $this->database_dumper->table_exists($table_name);
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

    private function require_job(string $job_id): string
    {
        $job_id = trim($job_id);
        if ($job_id === '' || !preg_match('/^[A-Za-z0-9_-]{1,128}$/', $job_id)) {
            throw new RuntimeException('Backup job not found.');
        }

        $temp_dir = trailingslashit($this->get_base_backup_dir()) . $job_id;
        if (!is_dir($temp_dir)) {
            throw new RuntimeException('Backup job not found.');
        }

        return $job_id;
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

    private function normalize_job_id(string $requested_job_id, string $backup_id): string
    {
        $job_id = trim($requested_job_id);
        if ($job_id === '') {
            return 'backup_' . substr(str_replace('-', '', $backup_id), 0, 8) . '_' . time();
        }

        if (!preg_match('/^[A-Za-z0-9_-]{1,128}$/', $job_id)) {
            throw new InvalidArgumentException('Invalid job_id format. Use only letters, numbers, underscores, and hyphens.');
        }

        return $job_id;
    }

    private function get_base_backup_dir(): string
    {
        if ($this->backup_base_dir !== '') {
            return $this->backup_base_dir;
        }

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

    private function get_file_checksum(string $absolute_path): string
    {
        $mtime = @filemtime($absolute_path);
        if ($mtime === false) {
            return hash_file('sha256', $absolute_path);
        }

        $cache_key = 'stack2_cksum_' . md5($absolute_path . ':' . $mtime);
        $cached = get_transient($cache_key);
        if ($cached !== false) {
            return (string) $cached;
        }

        $checksum = hash_file('sha256', $absolute_path);
        set_transient($cache_key, $checksum, WEEK_IN_SECONDS);

        return $checksum;
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
