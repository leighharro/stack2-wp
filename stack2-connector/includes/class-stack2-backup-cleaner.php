<?php

if (!defined('ABSPATH')) {
    exit;
}

class Stack2_Backup_Cleaner
{
    public function cleanup_backup(string $temp_dir): int
    {
        if (!is_dir($temp_dir)) {
            return 0;
        }

        $freed_space = $this->calculate_size($temp_dir);
        $this->delete_recursive($temp_dir);

        return (int) round($freed_space / 1048576);
    }

    public function cleanup_abandoned(string $base_dir, int $older_than_seconds = 86400): int
    {
        if (!is_dir($base_dir)) {
            return 0;
        }

        $freed_bytes = 0;
        $now = time();

        $entries = scandir($base_dir);
        if (!is_array($entries)) {
            return 0;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $candidate = trailingslashit($base_dir) . $entry;
            if (!is_dir($candidate)) {
                continue;
            }

            $modified = (int) @filemtime($candidate);
            if ($modified <= 0 || ($now - $modified) < $older_than_seconds) {
                continue;
            }

            $freed_bytes += $this->calculate_size($candidate);
            $this->delete_recursive($candidate);
        }

        return (int) round($freed_bytes / 1048576);
    }

    private function calculate_size(string $path): int
    {
        if (!file_exists($path)) {
            return 0;
        }

        if (is_file($path)) {
            return (int) filesize($path);
        }

        $size = 0;
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $item) {
            if ($item->isFile()) {
                $size += (int) $item->getSize();
            }
        }

        return $size;
    }

    private function delete_recursive(string $path): void
    {
        if (!file_exists($path)) {
            return;
        }

        if (is_file($path) || is_link($path)) {
            @unlink($path);
            return;
        }

        $entries = scandir($path);
        if (is_array($entries)) {
            foreach ($entries as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }

                $this->delete_recursive(trailingslashit($path) . $entry);
            }
        }

        @rmdir($path);
    }
}
