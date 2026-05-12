<?php

if (!defined('ABSPATH')) {
    exit;
}

class Stack2_Backup_Compressor
{
    private Stack2_Logger $logger;

    public function __construct(Stack2_Logger $logger)
    {
        $this->logger = $logger;
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
                $relative_path = ltrim(str_replace(ABSPATH, '', $path), '/');
                $totals['files'][] = $relative_path;
            }
        }

        return $totals;
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

            $relative_path = ltrim(str_replace(ABSPATH, '', $absolute_path), '/');
            $zip->addFile($absolute_path, $relative_path);
            $manifest_files[] = $relative_path;

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

    private function should_exclude(string $path): bool
    {
        $normalized = wp_normalize_path($path);
        $patterns = $this->get_exclusion_patterns();

        foreach ($patterns as $pattern) {
            if (strpos($normalized, $pattern) !== false) {
                return true;
            }
        }

        return false;
    }

    private function get_exclusion_patterns(): array
    {
        return array(
            '/node_modules/',
            '/.git/',
            '/cache/',
            '/wp-content/cache/',
            '/wp-content/upgrade/',
            '/.stack2-backup/',
            '/uploads/tmp/',
            '/uploads/cache/',
        );
    }
}
