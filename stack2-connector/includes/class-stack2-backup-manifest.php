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
        $upload_path = trim((string) get_option('upload_path', ''));
        $upload_url_path = trim((string) get_option('upload_url_path', ''));
        $source_paths = $this->build_source_paths(
            defined('ABSPATH') ? (string) ABSPATH : '',
            defined('WP_CONTENT_DIR') ? (string) WP_CONTENT_DIR : '',
            (string) ($uploads['basedir'] ?? ''),
            $upload_path
        );

        return array(
            'backup_id' => $backup_id,
            'job_id' => $job_id,
            'wordpress_version' => get_bloginfo('version'),
            'php_version' => PHP_VERSION,
            'connector_version' => defined('STACK2_CONNECTOR_VERSION') ? (string) STACK2_CONNECTOR_VERSION : '',
            'site_url' => get_site_url(),
            'home_url' => home_url('/'),
            'generated_at' => $generated_at,
            'backup_started_at' => $backup_started_at,
            'include_files' => $include_files,
            'include_database' => $include_database,
            'wp_content_path' => WP_CONTENT_DIR,
            'wp_uploads_path' => (string) ($uploads['basedir'] ?? ''),
            'source_paths' => $source_paths,
            'upload_path' => $upload_path,
            'upload_url_path' => $upload_url_path,
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

    /**
     * Absolute filesystem roots from live source PHP for migrate path detect/repair.
     * Trailing-slash normalised, unique, with realpath variants when they differ.
     *
     * @return array<int, string>
     */
    public function collect_source_paths(): array
    {
        $uploads = wp_upload_dir();

        return $this->build_source_paths(
            defined('ABSPATH') ? (string) ABSPATH : '',
            defined('WP_CONTENT_DIR') ? (string) WP_CONTENT_DIR : '',
            (string) ($uploads['basedir'] ?? ''),
            trim((string) get_option('upload_path', ''))
        );
    }

    /**
     * @return array<int, string>
     */
    public function build_source_paths(
        string $abspath,
        string $wp_content_dir,
        string $uploads_basedir,
        string $upload_path
    ): array {
        $paths = array();

        $this->add_source_path($paths, $abspath);

        $default_content = $this->normalize_source_dir(
            $abspath !== '' ? trailingslashit(wp_normalize_path($abspath)) . 'wp-content' : ''
        );
        $custom_content = $this->normalize_source_dir($wp_content_dir);
        if ($custom_content !== '' && $custom_content !== $default_content) {
            $this->add_source_path($paths, $wp_content_dir);
        }

        $this->add_source_path($paths, $uploads_basedir);

        $resolved_upload_path = $this->resolve_upload_path($upload_path, $abspath);
        if ($resolved_upload_path !== '') {
            $this->add_source_path($paths, $resolved_upload_path);
        }

        return array_values($paths);
    }

    /**
     * @param array<string, string> $paths
     */
    private function add_source_path(array &$paths, string $path): void
    {
        $normalized = $this->normalize_source_dir($path);
        if ($normalized === '' || $normalized === '/') {
            return;
        }

        $paths[$normalized] = $normalized;

        $existing = untrailingslashit($normalized);
        if ($existing === '' || !file_exists($existing)) {
            return;
        }

        $real = realpath($existing);
        if ($real === false) {
            return;
        }

        $real_normalized = $this->normalize_source_dir($real);
        if ($real_normalized === '' || $real_normalized === '/') {
            return;
        }

        $paths[$real_normalized] = $real_normalized;
    }

    private function resolve_upload_path(string $upload_path, string $abspath): string
    {
        $upload_path = trim($upload_path);
        if ($upload_path === '') {
            return '';
        }

        if ($this->is_absolute_path($upload_path)) {
            return $upload_path;
        }

        if (trim($abspath) === '') {
            return $upload_path;
        }

        return trailingslashit(wp_normalize_path($abspath)) . ltrim(wp_normalize_path($upload_path), '/');
    }

    private function is_absolute_path(string $path): bool
    {
        $normalized = wp_normalize_path($path);
        if ($normalized === '') {
            return false;
        }

        if ($normalized[0] === '/') {
            return true;
        }

        return (bool) preg_match('#^[A-Za-z]:/#', $normalized);
    }

    private function normalize_source_dir(string $path): string
    {
        $path = trim($path);
        if ($path === '' || strpos($path, "\0") !== false) {
            return '';
        }

        return trailingslashit(wp_normalize_path($path));
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
