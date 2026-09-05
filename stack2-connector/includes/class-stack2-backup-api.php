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

        $this->register_scan_route('/backups/(?P<job_id>[A-Za-z0-9_-]+)/files/scan');
        $this->register_scan_route('/backup/(?P<job_id>[A-Za-z0-9_-]+)/files/scan');
        $this->register_stats_route('/backups/(?P<job_id>[A-Za-z0-9_-]+)/files/stats');
        $this->register_stats_route('/backup/(?P<job_id>[A-Za-z0-9_-]+)/files/stats');

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

        register_rest_route('stack2/v1', '/backups/(?P<job_id>[A-Za-z0-9_-]+)/files/(?P<encoded_path>(?!scan$|stats$)[A-Za-z0-9_-]+)', array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => array($this, 'download_manifest_file'),
            'permission_callback' => '__return_true',
        ));

        register_rest_route('stack2/v1', '/backups/(?P<job_id>[A-Za-z0-9_-]+)/files/(?P<encoded_path>[A-Za-z0-9_-]+)/metadata', array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => array($this, 'get_file_metadata'),
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

        register_rest_route('stack2/v1', '/backup/(?P<job_id>[A-Za-z0-9_-]+)/files/(?P<encoded_path>(?!scan$|stats$)[A-Za-z0-9_-]+)', array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => array($this, 'download_manifest_file'),
            'permission_callback' => '__return_true',
        ));

        register_rest_route('stack2/v1', '/backup/(?P<job_id>[A-Za-z0-9_-]+)/files/(?P<encoded_path>[A-Za-z0-9_-]+)/metadata', array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => array($this, 'get_file_metadata'),
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

    public function initiate_backup(WP_REST_Request $request)
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
        $job_id = isset($payload['job_id']) ? sanitize_text_field((string) $payload['job_id']) : '';
        $include_files = isset($payload['include_files']) ? (bool) $payload['include_files'] : false;
        $include_database = isset($payload['include_database']) ? (bool) $payload['include_database'] : false;
        $requested_at = isset($payload['timestamp']) ? sanitize_text_field((string) $payload['timestamp']) : gmdate('c');

        try {
            $prepared = $this->backup_manager->prepare_backup($backup_id, $include_files, $include_database, $requested_at, $job_id);
            $manifest = $this->backup_manager->build_initiate_manifest($prepared);
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

        $limits = is_array($prepared['inventory_limits'] ?? null)
            ? $prepared['inventory_limits']
            : Stack2_Backup_File_Scanner::inventory_limits();

        return new WP_REST_Response(array(
            'success' => true,
            'error' => null,
            'backup_id' => $prepared['backup_id'],
            'job_id' => $prepared['job_id'],
            'status' => 'initiated',
            'manifest_mode' => 'agent',
            'scan' => $limits['scan'],
            'stats' => $limits['stats'],
            'manifest' => $manifest,
        ), 200);
    }

    public function scan_files(WP_REST_Request $request): WP_REST_Response
    {
        if ($this->site_id === '' || $this->api_key === '') {
            return new WP_REST_Response(array(
                'success' => false,
                'error' => 'Stack2 credentials are not configured.',
            ), 503);
        }

        $path = $this->get_signed_path($request);
        $auth = $this->verify($request, 'GET', $path, '');
        if (is_wp_error($auth)) {
            return $this->error_response_from_wp_error($auth);
        }

        $job_id = sanitize_text_field((string) $request->get_param('job_id'));
        $cursor = (string) ($request->get_param('cursor') ?? '');
        $limit = (int) ($request->get_param('limit') ?? Stack2_Backup_File_Scanner::DEFAULT_SCAN_LIMIT);
        $include_sha256 = $this->request_bool($request->get_param('include_sha256'), false);
        $include_dirs = $this->request_bool($request->get_param('include_dirs'), false);

        if (function_exists('set_time_limit')) {
            @set_time_limit(30);
        }

        try {
            $page = $this->backup_manager->scan_files($job_id, $cursor, $limit, $include_sha256, $include_dirs);
        } catch (InvalidArgumentException $e) {
            return new WP_REST_Response(array(
                'success' => false,
                'error' => $e->getMessage(),
                'job_id' => $job_id,
            ), 400);
        } catch (RuntimeException $e) {
            $not_found = $e->getMessage() === 'Backup job not found.';
            return new WP_REST_Response(array(
                'success' => false,
                'error' => $e->getMessage(),
                'job_id' => $job_id,
            ), $not_found ? 404 : 500);
        } catch (Throwable $e) {
            $this->logger->error('Backup file scan failed.', array(
                'job_id' => $job_id,
                'error' => $e->getMessage(),
            ));

            return new WP_REST_Response(array(
                'success' => false,
                'error' => 'Server error',
                'job_id' => $job_id,
            ), 500);
        }

        return new WP_REST_Response(array(
            'success' => true,
            'job_id' => $job_id,
            'entries' => $page['entries'],
            'next_cursor' => $page['next_cursor'],
            'has_more' => $page['has_more'],
            'scanned' => $page['scanned'],
        ), 200);
    }

    public function stat_files(WP_REST_Request $request): WP_REST_Response
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

        $job_id = sanitize_text_field((string) $request->get_param('job_id'));
        $payload = json_decode($raw_body, true);
        if (!is_array($payload)) {
            return new WP_REST_Response(array(
                'success' => false,
                'error' => 'Invalid JSON payload.',
                'job_id' => $job_id,
            ), 400);
        }

        if (!isset($payload['paths']) || !is_array($payload['paths'])) {
            return new WP_REST_Response(array(
                'success' => false,
                'error' => 'paths must be an array.',
                'job_id' => $job_id,
            ), 400);
        }

        if (count($payload['paths']) > Stack2_Backup_File_Scanner::MAX_STATS_BATCH) {
            return new WP_REST_Response(array(
                'success' => false,
                'error' => 'Too many paths. Maximum is ' . Stack2_Backup_File_Scanner::MAX_STATS_BATCH . '.',
                'job_id' => $job_id,
            ), 400);
        }

        $include_sha256 = $this->request_bool($payload['include_sha256'] ?? true, true);

        try {
            $result = $this->backup_manager->stat_files($job_id, $payload['paths'], $include_sha256);
        } catch (InvalidArgumentException $e) {
            return new WP_REST_Response(array(
                'success' => false,
                'error' => $e->getMessage(),
                'job_id' => $job_id,
            ), 400);
        } catch (RuntimeException $e) {
            $not_found = $e->getMessage() === 'Backup job not found.';
            return new WP_REST_Response(array(
                'success' => false,
                'error' => $e->getMessage(),
                'job_id' => $job_id,
            ), $not_found ? 404 : 500);
        } catch (Throwable $e) {
            $this->logger->error('Backup file stats failed.', array(
                'job_id' => $job_id,
                'error' => $e->getMessage(),
            ));

            return new WP_REST_Response(array(
                'success' => false,
                'error' => 'Server error',
                'job_id' => $job_id,
            ), 500);
        }

        return new WP_REST_Response(array(
            'success' => true,
            'stats' => $result['stats'],
            'missing' => $result['missing'],
            'failed' => $result['failed'],
        ), 200);
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

    public function get_file_metadata(WP_REST_Request $request): WP_REST_Response
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

        $metadata = $this->backup_manager->get_backup_manifest_file_metadata($job_id, $relative_path);
        if (!is_array($metadata)) {
            return new WP_REST_Response(array('success' => false, 'error' => 'Backup file not available.'), 404);
        }

        return new WP_REST_Response(array(
            'success' => true,
            'path' => $metadata['path'],
            'sha256' => $metadata['sha256'],
            'size' => $metadata['size'],
        ), 200);
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

        // Fast path: serve the pre-generated cached dump with full headers.
        $component_file = $this->backup_manager->get_cached_database_table_file($job_id, $table_name);

        if (is_array($component_file)) {
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
            return; // stream_file_download exits; this is unreachable but keeps the type checker happy
        }

        // Dump not cached yet. Verify the table exists before streaming.
        if (!$this->backup_manager->database_table_exists($table_name)) {
            return new WP_REST_Response(array('success' => false, 'error' => 'Database table backup is not available.'), 404);
        }

        // Stream the SQL dump directly to the HTTP response as rows are fetched.
        // The proxy sees data flowing from the first flush, so it never times out
        // regardless of table size or server environment.
        // Content-Length and X-Backup-Checksum-SHA256 are omitted on this first
        // response because the values are only known after generation completes.
        // The dump is saved to the cache file simultaneously, so all subsequent
        // requests are served from cache with full headers.
        @ini_set('zlib.output_compression', '0');
        while (ob_get_level() > 0) {
            @ob_end_clean();
        }

        header('Content-Type: application/gzip');
        header('Content-Disposition: attachment; filename=database-table-' . $table_name . '.sql.gz');
        header('X-Backup-Database-Table: ' . rawurlencode($table_name));

        try {
            $this->backup_manager->stream_database_table_to_output($job_id, $table_name);
        } catch (RuntimeException $e) {
            $this->logger->error('Table dump streaming failed.', array(
                'job_id'  => $job_id,
                'table'   => $table_name,
                'error'   => $e->getMessage(),
            ));
        }

        exit;
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

    private function register_scan_route(string $route): void
    {
        register_rest_route('stack2/v1', $route, array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => array($this, 'scan_files'),
            'permission_callback' => '__return_true',
            'args' => array(
                'cursor' => array(
                    'type' => 'string',
                    'required' => false,
                    'default' => '',
                ),
                'limit' => array(
                    'type' => 'integer',
                    'required' => false,
                    'default' => Stack2_Backup_File_Scanner::DEFAULT_SCAN_LIMIT,
                ),
                'include_sha256' => array(
                    'type' => 'boolean',
                    'required' => false,
                    'default' => false,
                ),
                'include_dirs' => array(
                    'type' => 'boolean',
                    'required' => false,
                    'default' => false,
                ),
            ),
        ));
    }

    private function register_stats_route(string $route): void
    {
        register_rest_route('stack2/v1', $route, array(
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => array($this, 'stat_files'),
            'permission_callback' => '__return_true',
        ));
    }

    private function request_bool($value, bool $default): bool
    {
        if ($value === null || $value === '') {
            return $default;
        }

        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return (int) $value === 1;
        }

        $normalized = strtolower(trim((string) $value));
        if (in_array($normalized, array('1', 'true', 'yes', 'on'), true)) {
            return true;
        }

        if (in_array($normalized, array('0', 'false', 'no', 'off'), true)) {
            return false;
        }

        return $default;
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

        $query_pos = strpos($path, '?');
        if ($query_pos !== false) {
            $candidates[] = substr($path, 0, $query_pos);
        }

        $trimmed = rtrim($query_pos !== false ? substr($path, 0, $query_pos) : $path, '/');
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
