<?php

if (!defined('ABSPATH')) {
    exit;
}

class Stack2_Backup_API
{
    private Stack2_Backup_Manager $backup_manager;
    private Stack2_Backup_Authentication $authentication;
    private Stack2_Logger $logger;
    private string $site_id;
    private string $api_key;

    public function __construct(
        Stack2_Backup_Manager $backup_manager,
        Stack2_Backup_Authentication $authentication,
        Stack2_Logger $logger,
        string $site_id,
        string $api_key
    ) {
        $this->backup_manager = $backup_manager;
        $this->authentication = $authentication;
        $this->logger = $logger;
        $this->site_id = $site_id;
        $this->api_key = $api_key;
    }

    public function register_routes(): void
    {
        register_rest_route('stack2/v1', '/backups/initiate', array(
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => array($this, 'initiate_backup'),
            'permission_callback' => '__return_true',
        ));

        // Backward-compatible alias for clients using singular route names.
        register_rest_route('stack2/v1', '/backup/initiate', array(
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => array($this, 'initiate_backup'),
            'permission_callback' => '__return_true',
        ));

        register_rest_route('stack2/v1', '/backups/(?P<job_id>[A-Za-z0-9_-]+)/status', array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => array($this, 'get_status'),
            'permission_callback' => '__return_true',
        ));

        register_rest_route('stack2/v1', '/backup/(?P<job_id>[A-Za-z0-9_-]+)/status', array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => array($this, 'get_status'),
            'permission_callback' => '__return_true',
        ));

        register_rest_route('stack2/v1', '/backups/(?P<job_id>[A-Za-z0-9_-]+)/cancel', array(
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => array($this, 'cancel_backup'),
            'permission_callback' => '__return_true',
        ));

        register_rest_route('stack2/v1', '/backup/(?P<job_id>[A-Za-z0-9_-]+)/cancel', array(
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => array($this, 'cancel_backup'),
            'permission_callback' => '__return_true',
        ));

        register_rest_route('stack2/v1', '/backups/(?P<job_id>[A-Za-z0-9_-]+)/download/(?P<component>files|database|both)', array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => array($this, 'download_component'),
            'permission_callback' => '__return_true',
        ));

        register_rest_route('stack2/v1', '/backups/(?P<job_id>[A-Za-z0-9_-]+)/files/(?P<encoded_path>[A-Za-z0-9_-]+)', array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => array($this, 'download_manifest_file'),
            'permission_callback' => '__return_true',
        ));

        register_rest_route('stack2/v1', '/backups/(?P<job_id>[A-Za-z0-9_-]+)/database/table/(?P<encoded_table>[A-Za-z0-9_-]+)', array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => array($this, 'download_database_table'),
            'permission_callback' => '__return_true',
        ));

        register_rest_route('stack2/v1', '/backup/(?P<job_id>[A-Za-z0-9_-]+)/download/(?P<component>files|database|both)', array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => array($this, 'download_component'),
            'permission_callback' => '__return_true',
        ));

        register_rest_route('stack2/v1', '/backup/(?P<job_id>[A-Za-z0-9_-]+)/files/(?P<encoded_path>[A-Za-z0-9_-]+)', array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => array($this, 'download_manifest_file'),
            'permission_callback' => '__return_true',
        ));

        register_rest_route('stack2/v1', '/backup/(?P<job_id>[A-Za-z0-9_-]+)/database/table/(?P<encoded_table>[A-Za-z0-9_-]+)', array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => array($this, 'download_database_table'),
            'permission_callback' => '__return_true',
        ));

        register_rest_route('stack2/v1', '/backups/(?P<job_id>[A-Za-z0-9_-]+)', array(
            'methods' => WP_REST_Server::DELETABLE,
            'callback' => array($this, 'cleanup_backup'),
            'permission_callback' => '__return_true',
        ));

        register_rest_route('stack2/v1', '/backup/(?P<job_id>[A-Za-z0-9_-]+)', array(
            'methods' => WP_REST_Server::DELETABLE,
            'callback' => array($this, 'cleanup_backup'),
            'permission_callback' => '__return_true',
        ));

        register_rest_route('stack2/v1', '/backups', array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => array($this, 'list_backups'),
            'permission_callback' => '__return_true',
        ));

        register_rest_route('stack2/v1', '/backup', array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => array($this, 'list_backups'),
            'permission_callback' => '__return_true',
        ));
    }

    public function initiate_backup(WP_REST_Request $request): WP_REST_Response
    {
        if ($this->site_id === '' || $this->api_key === '') {
            return new WP_REST_Response(array(
                'success' => false,
                'error' => 'Stack2 credentials are not configured.',
            ), 503);
        }

        $raw_body = $request->get_body();
        $auth = $this->verify($request, 'POST', $this->get_signed_path($request), $raw_body);
        if (is_wp_error($auth)) {
            return $this->error_response_from_wp_error($auth);
        }

        $payload = json_decode($raw_body, true);
        if (!is_array($payload)) {
            return new WP_REST_Response(array('success' => false, 'error' => 'Invalid JSON payload.'), 400);
        }

        $backup_id = isset($payload['backup_id']) ? sanitize_text_field((string) $payload['backup_id']) : '';
        $include_files = isset($payload['include_files']) ? (bool) $payload['include_files'] : false;
        $include_database = isset($payload['include_database']) ? (bool) $payload['include_database'] : false;
        $requested_at = isset($payload['timestamp']) ? sanitize_text_field((string) $payload['timestamp']) : gmdate('c');

        try {
            $job = $this->backup_manager->initiate_backup($backup_id, $include_files, $include_database, $requested_at);

            return new WP_REST_Response(array(
                'success' => true,
                'error' => null,
                'backup_id' => $job['backup_id'],
                'job_id' => $job['job_id'],
                'status' => 'initiated',
                'manifest' => $job['manifest'],
            ), 200);
        } catch (InvalidArgumentException $e) {
            return new WP_REST_Response(array(
                'success' => false,
                'error' => $e->getMessage(),
                'backup_id' => $backup_id,
                'job_id' => null,
                'status' => 'failed',
            ), 400);
        } catch (RuntimeException $e) {
            $status = $e->getMessage() === 'Another backup is already in progress' ? 409 : 500;

            return new WP_REST_Response(array(
                'success' => false,
                'error' => $e->getMessage(),
                'backup_id' => $backup_id,
                'job_id' => null,
                'status' => 'failed',
            ), $status);
        } catch (Throwable $e) {
            $this->logger->error('Backup initiation failed.', array('error' => $e->getMessage()));

            return new WP_REST_Response(array(
                'success' => false,
                'error' => 'Server error',
                'backup_id' => $backup_id,
                'job_id' => null,
                'status' => 'failed',
            ), 500);
        }
    }

    public function get_status(WP_REST_Request $request): WP_REST_Response
    {
        $path = $this->get_signed_path($request);

        $auth = $this->verify($request, 'GET', $path, '');
        if (is_wp_error($auth)) {
            return $this->error_response_from_wp_error($auth);
        }

        return new WP_REST_Response(array(
            'success' => false,
            'error' => 'Backup status endpoint is deprecated in stateless mode.',
        ), 410);
    }

    public function download_component(WP_REST_Request $request)
    {
        $path = $this->get_signed_path($request);

        $auth = $this->verify($request, 'GET', $path, '');
        if (is_wp_error($auth)) {
            return $this->error_response_from_wp_error($auth);
        }

        return new WP_REST_Response(array(
            'success' => false,
            'error' => 'Component archive downloads are deprecated. Use /backups/{job_id}/files/{base64url_relative_path} and /backups/{job_id}/database/table/{base64url_table_name}.',
        ), 410);
    }

    public function download_manifest_file(WP_REST_Request $request)
    {
        $job_id = sanitize_text_field((string) $request->get_param('job_id'));
        $encoded_path = sanitize_text_field((string) $request->get_param('encoded_path'));
        $path = $this->get_signed_path($request);

        $auth = $this->verify($request, 'GET', $path, '');
        if (is_wp_error($auth)) {
            return $this->error_response_from_wp_error($auth);
        }

        $relative_path = $this->decode_relative_path($encoded_path);
        if ($relative_path === '') {
            return new WP_REST_Response(array('success' => false, 'error' => 'Invalid encoded file path.'), 400);
        }

        $component_file = $this->backup_manager->get_backup_manifest_file($job_id, $relative_path);
        if (!is_array($component_file)) {
            return new WP_REST_Response(array('success' => false, 'error' => 'Backup file not available.'), 404);
        }

        $file_path = $component_file['path'];
        if (!file_exists($file_path)) {
            return new WP_REST_Response(array('success' => false, 'error' => 'Backup file missing.'), 404);
        }

        header('Content-Type: ' . $component_file['content_type']);
        header('Content-Disposition: attachment; filename=' . basename($component_file['filename']));
        header('Content-Length: ' . (string) $component_file['size']);
        header('X-Backup-Checksum-SHA256: ' . $component_file['checksum']);
        header('X-Backup-Relative-Path: ' . rawurlencode((string) ($component_file['relative_path'] ?? '')));

        $this->stream_file_download($file_path);
    }

    public function download_database_table(WP_REST_Request $request)
    {
        $job_id = sanitize_text_field((string) $request->get_param('job_id'));
        $encoded_table = sanitize_text_field((string) $request->get_param('encoded_table'));
        $path = $this->get_signed_path($request);

        $auth = $this->verify($request, 'GET', $path, '');
        if (is_wp_error($auth)) {
            return $this->error_response_from_wp_error($auth);
        }

        $table_name = $this->decode_relative_path($encoded_table);
        if ($table_name === '') {
            return new WP_REST_Response(array('success' => false, 'error' => 'Invalid encoded table name.'), 400);
        }

        try {
            $component_file = $this->backup_manager->get_backup_database_table_file($job_id, $table_name);
        } catch (RuntimeException $e) {
            return new WP_REST_Response(array('success' => false, 'error' => $e->getMessage()), 400);
        }

        if (!is_array($component_file)) {
            return new WP_REST_Response(array('success' => false, 'error' => 'Database table backup is not available.'), 404);
        }

        $file_path = $component_file['path'];
        if (!file_exists($file_path)) {
            return new WP_REST_Response(array('success' => false, 'error' => 'Database table backup file missing.'), 404);
        }

        header('Content-Type: ' . $component_file['content_type']);
        header('Content-Disposition: attachment; filename=' . basename($component_file['filename']));
        header('Content-Length: ' . (string) $component_file['size']);
        header('X-Backup-Checksum-SHA256: ' . $component_file['checksum']);
        header('X-Backup-Database-Table: ' . rawurlencode((string) ($component_file['table'] ?? '')));

        $this->stream_file_download($file_path);
    }

    public function cleanup_backup(WP_REST_Request $request): WP_REST_Response
    {
        $job_id = sanitize_text_field((string) $request->get_param('job_id'));
        $path = $this->get_signed_path($request);

        $auth = $this->verify($request, 'DELETE', $path, '');
        if (is_wp_error($auth)) {
            return $this->error_response_from_wp_error($auth);
        }

        $cleanup = $this->backup_manager->cleanup_backup($job_id);
        if (!is_array($cleanup)) {
            return new WP_REST_Response(array('success' => false, 'error' => 'Backup job not found.'), 404);
        }

        return new WP_REST_Response(array(
            'success' => true,
            'job_id' => $cleanup['job_id'],
            'message' => 'Temporary backup files deleted',
            'temp_directory' => $cleanup['temp_directory'],
            'freed_space_mb' => $cleanup['freed_space_mb'],
        ), 200);
    }

    public function cancel_backup(WP_REST_Request $request): WP_REST_Response
    {
        $path = $this->get_signed_path($request);

        $auth = $this->verify($request, 'POST', $path, '');
        if (is_wp_error($auth)) {
            return $this->error_response_from_wp_error($auth);
        }

        return new WP_REST_Response(array(
            'success' => false,
            'error' => 'Backup cancellation endpoint is deprecated in stateless mode.',
        ), 410);
    }

    public function list_backups(WP_REST_Request $request): WP_REST_Response
    {
        $path = $this->get_signed_path($request);
        $auth = $this->verify($request, 'GET', $path, '');
        if (is_wp_error($auth)) {
            return $this->error_response_from_wp_error($auth);
        }

        return new WP_REST_Response(array(
            'success' => false,
            'error' => 'Backup listing endpoint is deprecated in stateless mode.',
        ), 410);
    }

    private function verify(WP_REST_Request $request, string $method, string $path, string $raw_body)
    {
        if ($this->site_id === '' || $this->api_key === '') {
            return new WP_Error('stack2_missing_credentials', 'Stack2 credentials are not configured.', array('status' => 503));
        }

        $primary = $this->authentication->verify_request(
            $request,
            $this->site_id,
            $this->api_key,
            $method,
            $path,
            $raw_body
        );

        if (!is_wp_error($primary)) {
            return true;
        }

        if ($primary->get_error_code() !== 'stack2_bad_signature') {
            return $primary;
        }

        foreach ($this->get_alternate_signed_paths($path) as $alternate_path) {
            $alternate = $this->authentication->verify_request(
                $request,
                $this->site_id,
                $this->api_key,
                $method,
                $alternate_path,
                $raw_body
            );

            if (!is_wp_error($alternate)) {
                $this->logger->info('Backup signature accepted via alternate path normalization.', array(
                    'method' => $method,
                    'path' => $path,
                    'alternate_path' => $alternate_path,
                ));

                return true;
            }
        }

        return $primary;
    }

    private function error_response_from_wp_error(WP_Error $error): WP_REST_Response
    {
        $error_data = $error->get_error_data();
        $status = (int) (is_array($error_data) && isset($error_data['status']) ? $error_data['status'] : 401);

        return new WP_REST_Response(array(
            'success' => false,
            'error' => $error->get_error_message(),
        ), $status);
    }

    private function get_signed_path(WP_REST_Request $request): string
    {
        $route = (string) $request->get_route();
        if ($route === '') {
            return '/wp-json/stack2/v1/backups';
        }

        if ($route[0] !== '/') {
            $route = '/' . $route;
        }

        if (strpos($route, '/wp-json/') === 0) {
            return $route;
        }

        return '/wp-json' . $route;
    }

    private function get_alternate_signed_paths(string $path): array
    {
        $candidates = array();

        $trimmed = rtrim($path, '/');
        if ($trimmed !== '') {
            $candidates[] = $trimmed;
            $candidates[] = $trimmed . '/';
        }

        if (strpos($path, '/wp-json') === 0) {
            $without_prefix = substr($path, strlen('/wp-json'));
            if ($without_prefix !== false && $without_prefix !== '') {
                $candidates[] = $without_prefix;
                $candidates[] = rtrim($without_prefix, '/');
            }
        } else {
            $candidates[] = '/wp-json' . $path;
        }

        $singular_plural_swaps = array(
            '/stack2/v1/backup/' => '/stack2/v1/backups/',
            '/stack2/v1/backups/' => '/stack2/v1/backup/',
            '/stack2/v1/backup' => '/stack2/v1/backups',
            '/stack2/v1/backups' => '/stack2/v1/backup',
        );

        $seed = array_merge(array($path), $candidates);
        foreach ($seed as $item) {
            foreach ($singular_plural_swaps as $from => $to) {
                if (strpos($item, $from) !== false) {
                    $candidates[] = str_replace($from, $to, $item);
                }
            }
        }

        $unique = array();
        foreach ($candidates as $candidate) {
            if (!is_string($candidate) || $candidate === '' || $candidate === $path) {
                continue;
            }
            $unique[$candidate] = true;
        }

        return array_keys($unique);
    }

    private function decode_relative_path(string $encoded_path): string
    {
        $value = trim($encoded_path);
        if ($value === '') {
            return '';
        }

        $base64 = strtr($value, '-_', '+/');
        $padding = strlen($base64) % 4;
        if ($padding > 0) {
            $base64 .= str_repeat('=', 4 - $padding);
        }

        $decoded = base64_decode($base64, true);
        if ($decoded === false) {
            return '';
        }

        return (string) $decoded;
    }

    private function stream_file_download(string $file_path): void
    {
        if (function_exists('apache_setenv')) {
            @apache_setenv('no-gzip', '1');
        }

        @ini_set('zlib.output_compression', '0');
        @ini_set('output_buffering', '0');

        while (ob_get_level() > 0) {
            @ob_end_clean();
        }

        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }

        $handle = fopen($file_path, 'rb');
        if ($handle === false) {
            exit;
        }

        // Stream in fixed-size chunks to keep process memory usage stable.
        $chunk_size = 1048576;

        while (!feof($handle)) {
            $chunk = fread($handle, $chunk_size);
            if ($chunk === false) {
                break;
            }

            echo $chunk;
            flush();

            if (connection_aborted()) {
                break;
            }
        }

        fclose($handle);
        exit;
    }
}
