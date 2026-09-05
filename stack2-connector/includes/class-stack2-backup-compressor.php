<?php

if (!defined('ABSPATH')) {
    exit;
}

class Stack2_Backup_Compressor
{
    private Stack2_Logger $logger;
    private string $wordpress_root;

    public function __construct(Stack2_Logger $logger, ?string $wordpress_root = null)
    {
        $this->logger = $logger;
        $this->wordpress_root = $wordpress_root !== null && $wordpress_root !== ''
            ? $wordpress_root
            : (defined('ABSPATH') ? (string) ABSPATH : '');
    }

    public function estimate_wp_content(string $wordpress_root_path, bool $include_manifest_files = false): array
    {
        $totals = array(
            'files_count' => 0,
            'bytes_total' => 0,
            'files' => array(),
        );

        if (!is_dir($wordpress_root_path)) {
            return $totals;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($wordpress_root_path, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }

            $path = $file->getPathname();
            if ($this->should_exclude($path)) {
                continue;
            }

            $totals['files_count']++;
            $totals['bytes_total'] += (int) $file->getSize();

            if ($include_manifest_files) {
                $metadata = $this->build_file_metadata($path);
                if ($metadata !== null) {
                    $totals['files'][] = $metadata;
                }
            }
        }

        return $totals;
    }

    /**
     * Walks the WordPress install computing a checksum for every file, invoking
     * $on_file for each one instead of accumulating them in memory.
     */
    public function walk_wp_content_with_checksums(string $wordpress_root_path, callable $on_file): array
    {
        $files_count = 0;
        $bytes_total = 0;

        if (!is_dir($wordpress_root_path)) {
            return array('files_count' => 0, 'bytes_total' => 0);
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($wordpress_root_path, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }

            $path = $file->getPathname();
            if ($this->should_exclude($path)) {
                continue;
            }

            $metadata = $this->build_file_metadata($path);
            if ($metadata === null) {
                continue;
            }

            $files_count++;
            $bytes_total += (int) $file->getSize();
            $on_file($metadata);
        }

        return array('files_count' => $files_count, 'bytes_total' => $bytes_total);
    }

    /**
     * Hashes one relative path (using the mtime checksum cache) and returns
     * {path, sha256, size} or null if the file cannot be read.
     */
    public function metadata_for_relative_path(string $relative_path): ?array
    {
        $relative_path = ltrim(wp_normalize_path($relative_path), '/');
        if ($relative_path === '' || strpos($relative_path, '../') !== false) {
            return null;
        }

        $absolute = trailingslashit($this->wordpress_root) . $relative_path;
        if ($this->is_excluded($absolute, is_dir($absolute))) {
            return null;
        }

        return $this->build_file_metadata($absolute);
    }

    public function is_excluded(string $path, bool $is_directory = false): bool
    {
        $normalized = wp_normalize_path($path);
        if (!$is_directory && $this->is_excluded_log_basename(basename($normalized))) {
            return true;
        }

        if ($is_directory) {
            $normalized = trailingslashit($normalized);
        }

        foreach (self::EXCLUSION_PATTERNS as $pattern) {
            if (strpos($normalized, $pattern) !== false) {
                return true;
            }
        }

        return false;
    }

    public function checksum_for_path(string $absolute_path): string
    {
        return $this->get_cached_file_checksum($absolute_path);
    }

    public function compress_wp_content(string $wordpress_root_path, string $temp_dir, callable $progress_callback): array
    {
        if (!class_exists('ZipArchive')) {
            throw new RuntimeException('ZipArchive is not available.');
        }

        if (!is_dir($wordpress_root_path)) {
            throw new RuntimeException('WordPress root directory does not exist.');
        }

        $zip_file = trailingslashit($temp_dir) . 'wp-content-files.zip';
        $zip = new ZipArchive();
        $open_result = $zip->open($zip_file, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        if ($open_result !== true) {
            throw new RuntimeException('Unable to create files archive.');
        }

        $estimate = $this->estimate_wp_content($wordpress_root_path);
        $files_total = max(1, (int) $estimate['files_count']);
        $bytes_total = max(1, (int) $estimate['bytes_total']);

        $files_processed = 0;
        $bytes_processed = 0;
        $manifest_files = array();

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($wordpress_root_path, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }

            $absolute_path = $file->getPathname();
            if ($this->should_exclude($absolute_path)) {
                continue;
            }

            $relative_path = $this->normalize_relative_path($absolute_path);
            $metadata = $this->build_file_metadata($absolute_path);
            if ($metadata === null) {
                continue;
            }

            $zip->addFile($absolute_path, $relative_path);
            $manifest_files[] = $metadata;

            $files_processed++;
            $bytes_processed += (int) $file->getSize();

            $progress_callback(array(
                'phase' => 'file_compression',
                'files_processed' => $files_processed,
                'files_total' => $files_total,
                'database_rows_processed' => 0,
                'database_rows_total' => 0,
                'bytes_processed' => $bytes_processed,
                'bytes_total' => $bytes_total,
                'percent' => (int) min(99, floor(($files_processed / $files_total) * 100)),
                'current_file' => $relative_path,
            ));
        }

        $zip->close();

        return array(
            'file' => $zip_file,
            'size_bytes' => file_exists($zip_file) ? (int) filesize($zip_file) : 0,
            'files_count' => $files_processed,
            'files' => $manifest_files,
        );
    }

    private const EXCLUSION_PATTERNS = array(
        '/node_modules/',
        '/.git/',
        '/cache/',
        '/wp-content/cache/',
        '/wp-content/upgrade/',
        '/.stack2-backup/',
        '/uploads/tmp/',
        '/uploads/cache/',
        '/wp-content/ai1wm-backups/',
        '/wp-content/updraft/',
        '/wp-content/wpvivid/',
        '/wp-content/backwpup-',
        '/wp-snapshots/',
    );

    private function should_exclude(string $path): bool
    {
        return $this->is_excluded($path, is_dir($path));
    }

    /**
     * PHP/host logs change while being read and must not be hashed.
     * Matches error_log, php_errorlog, debug.log, and any *.log basename.
     * Does not match names such as error_log.bak or something.log.bak.
     */
    private function is_excluded_log_basename(string $basename): bool
    {
        $name = strtolower($basename);
        if ($name === 'error_log' || $name === 'php_errorlog' || $name === 'debug.log') {
            return true;
        }

        return str_ends_with($name, '.log');
    }

    private function build_file_metadata(string $absolute_path): ?array
    {
        if (!is_file($absolute_path) || !is_readable($absolute_path)) {
            return null;
        }

        $size = @filesize($absolute_path);
        if ($size === false) {
            return null;
        }

        $sha256 = $this->get_cached_file_checksum($absolute_path);
        if ($sha256 === '') {
            return null;
        }

        $relative_path = $this->normalize_relative_path($absolute_path);

        return array(
            'path' => $relative_path,
            'sha256' => strtolower($sha256),
            'size' => (int) $size,
        );
    }

    private function get_cached_file_checksum(string $absolute_path): string
    {
        $mtime = @filemtime($absolute_path);
        if ($mtime === false) {
            $checksum = @hash_file('sha256', $absolute_path);
            return $checksum === false ? '' : $checksum;
        }

        $cache_key = 'stack2_cksum_' . md5($absolute_path . ':' . $mtime);
        $cached = get_transient($cache_key);
        if ($cached !== false) {
            return (string) $cached;
        }

        $checksum = @hash_file('sha256', $absolute_path);
        if ($checksum === false) {
            return '';
        }

        set_transient($cache_key, $checksum, WEEK_IN_SECONDS);

        return $checksum;
    }

    private function normalize_relative_path(string $absolute_path): string
    {
        $normalized = wp_normalize_path($absolute_path);
        $root = trailingslashit(wp_normalize_path($this->wordpress_root !== '' ? $this->wordpress_root : ABSPATH));

        if (strpos($normalized, $root) === 0) {
            $relative = substr($normalized, strlen($root));
        } else {
            $relative = ltrim($normalized, '/');
        }

        $relative = ltrim($relative, '/');
        if (strpos($relative, './') === 0) {
            $relative = substr($relative, 2);
        }

        return $relative;
    }
}
