<?php

if (!defined('ABSPATH')) {
    exit;
}

class Stack2_Restore_API
{
    private Stack2_Restore_Script_Store $store;
    private Stack2_Backup_Authentication $authentication;
    private Stack2_Logger $logger;
    private string $site_id;
    private string $api_key;

    public function __construct(
        Stack2_Restore_Script_Store $store,
        Stack2_Backup_Authentication $authentication,
        Stack2_Logger $logger,
        string $site_id,
        string $api_key
    ) {
        $this->store = $store;
        $this->authentication = $authentication;
        $this->logger = $logger;
        $this->site_id = $site_id;
        $this->api_key = $api_key;
    }

    public function register_routes(): void
    {
        register_rest_route('stack2/v1', '/restore-script', array(
            array(
                'methods' => array('PUT', WP_REST_Server::CREATABLE),
                'callback' => array($this, 'place_script'),
                'permission_callback' => '__return_true',
            ),
            array(
                'methods' => WP_REST_Server::DELETABLE,
                'callback' => array($this, 'delete_script'),
                'permission_callback' => '__return_true',
            ),
        ));
    }

    public function place_script(WP_REST_Request $request): WP_REST_Response
    {
        $this->store->expire_if_stale();

        if (!$this->is_connected()) {
            return $this->error_response('Connector is disconnected or not ready.', 503);
        }

        $raw_body = (string) $request->get_body();
        $method = $this->request_http_method($request, 'PUT');
        $auth = $this->verify($request, $method, $this->get_signed_path($request), $raw_body);
        if (is_wp_error($auth)) {
            return $this->error_response_from_wp_error($auth);
        }

        $parsed = $this->parse_place_request($request, $raw_body);
        if (is_wp_error($parsed)) {
            return $this->error_response_from_wp_error($parsed);
        }

        $result = $this->store->place($parsed['filename'], $parsed['contents'], $parsed['ttl_seconds']);
        if (is_wp_error($result)) {
            return $this->error_response_from_wp_error($result);
        }

        return new WP_REST_Response(array(
            'success' => true,
            'error' => null,
            'filename' => $result['filename'],
            'bytes' => $result['bytes'],
            'ttl_seconds' => $result['ttl_seconds'],
        ), 200);
    }

    public function delete_script(WP_REST_Request $request): WP_REST_Response
    {
        $this->store->expire_if_stale();

        if (!$this->is_connected()) {
            return $this->error_response('Connector is disconnected or not ready.', 503);
        }

        $raw_body = (string) $request->get_body();
        $auth = $this->verify($request, 'DELETE', $this->get_signed_path($request), $raw_body);
        if (is_wp_error($auth)) {
            return $this->error_response_from_wp_error($auth);
        }

        $filename = $this->parse_delete_filename($request, $raw_body);
        if (is_wp_error($filename)) {
            return $this->error_response_from_wp_error($filename);
        }

        $result = $this->store->delete($filename);
        if (is_wp_error($result)) {
            return $this->error_response_from_wp_error($result);
        }

        return new WP_REST_Response(array(
            'success' => true,
            'error' => null,
            'filename' => $result['filename'],
            'deleted' => (bool) $result['deleted'],
            'already_gone' => (bool) $result['already_gone'],
        ), 200);
    }

    private function is_connected(): bool
    {
        return $this->site_id !== '' && $this->api_key !== '';
    }

    /**
     * @return array{filename: string, contents: string, ttl_seconds: int|null}|WP_Error
     */
    private function parse_place_request(WP_REST_Request $request, string $raw_body)
    {
        $filename = $this->request_filename($request);
        $ttl_seconds = $request->get_param('ttl_seconds');
        $contents = $raw_body;

        $decoded = json_decode($raw_body, true);
        if (is_array($decoded) && $this->looks_like_json_place($decoded)) {
            $filename = isset($decoded['filename']) ? (string) $decoded['filename'] : $filename;
            $ttl_seconds = $decoded['ttl_seconds'] ?? $ttl_seconds;

            if (!empty($decoded['content_base64'])) {
                $contents = base64_decode((string) $decoded['content_base64'], true);
                if ($contents === false) {
                    return new WP_Error('stack2_restore_bad_base64', 'Restore script content_base64 is invalid.', array('status' => 400));
                }
            } elseif (array_key_exists('content', $decoded)) {
                $contents = (string) $decoded['content'];
            } else {
                return new WP_Error('stack2_restore_empty', 'Restore script body is required.', array('status' => 400));
            }
        }

        if ($filename === null || $filename === '') {
            return new WP_Error('stack2_restore_filename_required', 'Restore script filename is required.', array('status' => 400));
        }

        return array(
            'filename' => (string) $filename,
            'contents' => (string) $contents,
            'ttl_seconds' => $ttl_seconds,
        );
    }

    private function parse_delete_filename(WP_REST_Request $request, string $raw_body)
    {
        $filename = $this->request_filename($request);
        $decoded = json_decode($raw_body, true);
        if (is_array($decoded) && array_key_exists('filename', $decoded)) {
            $filename = (string) $decoded['filename'];
        }

        if ($filename === null || $filename === '') {
            return null;
        }

        return (string) $filename;
    }

    private function request_filename(WP_REST_Request $request): ?string
    {
        $header = trim((string) $request->get_header('x-stack2-restore-filename'));
        if ($header !== '') {
            return $header;
        }

        $param = $request->get_param('filename');
        if ($param !== null && $param !== '') {
            return (string) $param;
        }

        return null;
    }

    private function looks_like_json_place(array $decoded): bool
    {
        return array_key_exists('content', $decoded)
            || array_key_exists('content_base64', $decoded)
            || array_key_exists('filename', $decoded);
    }

    private function request_http_method(WP_REST_Request $request, string $default): string
    {
        if (method_exists($request, 'get_method')) {
            $method = strtoupper(trim((string) $request->get_method()));
            if ($method !== '') {
                return $method;
            }
        }

        return $default;
    }

    private function verify(WP_REST_Request $request, string $method, string $path, string $raw_body)
    {
        if ($this->site_id === '' || $this->api_key === '') {
            return new WP_Error('stack2_missing_credentials', 'Connector is disconnected or not ready.', array('status' => 503));
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
            $this->logger->error('Restore script request rejected.', array(
                'method' => $method,
                'path' => $path,
            ));
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
                return true;
            }
        }

        $this->logger->error('Restore script request rejected.', array(
            'method' => $method,
            'path' => $path,
        ));

        return $primary;
    }

    private function get_signed_path(WP_REST_Request $request): string
    {
        $route = (string) $request->get_route();
        if ($route === '') {
            return '/wp-json/stack2/v1/restore-script';
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

        $unique = array();
        foreach ($candidates as $candidate) {
            if (!is_string($candidate) || $candidate === '' || $candidate === $path) {
                continue;
            }
            $unique[$candidate] = true;
        }

        return array_keys($unique);
    }

    private function error_response(string $message, int $status): WP_REST_Response
    {
        return new WP_REST_Response(array(
            'success' => false,
            'error' => $message,
        ), $status);
    }

    private function error_response_from_wp_error(WP_Error $error): WP_REST_Response
    {
        $error_data = $error->get_error_data();
        $status = (int) (is_array($error_data) && isset($error_data['status']) ? $error_data['status'] : 401);

        return $this->error_response($error->get_error_message(), $status);
    }
}
