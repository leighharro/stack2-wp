<?php

if (!defined('ABSPATH')) {
    exit;
}

class Stack2_Backup_Manifest
{
    public function build_manifest(
        string $backup_id,
        string $job_id,
        bool $include_files,
        bool $include_database,
        array $database_info,
        array $file_info = array(),
        array $options = array()
    ): array {
        $uploads = wp_upload_dir();
        $manifest_tables = $this->sanitize_manifest_tables($database_info['tables'] ?? array());
        $generated_at = gmdate('c');
        $backup_started_at = isset($options['backup_started_at']) && $options['backup_started_at'] !== ''
            ? (string) $options['backup_started_at']
            : $generated_at;
        $manifest_complete = array_key_exists('manifest_complete', $options)
            ? (bool) $options['manifest_complete']
            : !$include_files;

        return array(
            'backup_id' => $backup_id,
            'job_id' => $job_id,
            'wordpress_version' => get_bloginfo('version'),
            'php_version' => PHP_VERSION,
            'site_url' => get_site_url(),
            'home_url' => home_url('/'),
            'generated_at' => $generated_at,
            'backup_started_at' => $backup_started_at,
            'include_files' => $include_files,
            'include_database' => $include_database,
            'wp_content_path' => WP_CONTENT_DIR,
            'wp_uploads_path' => (string) ($uploads['basedir'] ?? ''),
            'database' => array(
                'host' => (string) ($database_info['host'] ?? ''),
                'port' => (int) ($database_info['port'] ?? 3306),
                'name' => (string) ($database_info['name'] ?? ''),
                'charset' => (string) ($database_info['charset'] ?? ''),
                'collation' => (string) ($database_info['collation'] ?? ''),
            ),
            'estimated_files_count' => (int) ($file_info['files_count'] ?? 0),
            'estimated_database_size_mb' => (int) ceil(((int) ($database_info['size_bytes'] ?? 0)) / 1048576),
            'tables_count' => (int) ($database_info['tables_count'] ?? 0),
            'files' => array(),
            'tables' => $include_database ? $manifest_tables : array(),
            'manifest_mode' => (string) ($options['manifest_mode'] ?? 'agent'),
            'manifest_complete' => $manifest_complete,
        );
    }

    private function sanitize_manifest_files($files): array
    {
        if (!is_array($files)) {
            return array();
        }

        $sanitized = array();
        $seen_paths = array();

        foreach ($files as $file) {
            if (is_string($file)) {
                $file = array('path' => $file);
            }

            if (!is_array($file) || !isset($file['path'])) {
                continue;
            }

            $path = $this->sanitize_relative_path((string) $file['path']);
            if ($path === '' || isset($seen_paths[$path])) {
                continue;
            }

            $sha256 = isset($file['sha256']) ? strtolower(trim((string) $file['sha256'])) : '';
            if ($sha256 !== '' && !preg_match('/^[a-f0-9]{64}$/', $sha256)) {
                continue;
            }

            $size = isset($file['size']) ? (int) $file['size'] : 0;
            if ($size < 0) {
                $size = 0;
            }

            $seen_paths[$path] = true;
            $sanitized[] = array(
                'path' => $path,
                'sha256' => $sha256,
                'size' => $size,
            );
        }

        return $sanitized;
    }

    private function sanitize_relative_path(string $relative_path): string
    {
        $path = trim($relative_path);
        if ($path === '' || strpos($path, "\0") !== false) {
            return '';
        }

        $path = wp_normalize_path($path);
        $path = ltrim($path, '/');
        $path = preg_replace('#^\./+#', '', $path);

        if ($path === '' || strpos($path, '../') !== false || $path === '..') {
            return '';
        }

        return $path;
    }

    private function sanitize_manifest_tables($tables): array
    {
        if (!is_array($tables)) {
            return array();
        }

        $sanitized = array();
        foreach ($tables as $table) {
            $value = trim((string) $table);
            if ($value === '') {
                continue;
            }
            $sanitized[] = $value;
        }

        return array_values(array_unique($sanitized));
    }

    public function save_manifest(array $manifest, string $temp_dir): string
    {
        $path = trailingslashit($temp_dir) . 'manifest.json';

        $json = wp_json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new RuntimeException('Failed to encode backup manifest.');
        }

        if (file_put_contents($path, $json) === false) {
            throw new RuntimeException('Failed to write backup manifest.');
        }

        return $path;
    }
}
