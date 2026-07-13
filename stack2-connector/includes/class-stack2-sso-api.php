<?php

if (!defined('ABSPATH')) {
    exit;
}

class Stack2_SSO_API
{
    private Stack2_SSO_Service $sso_service;
    private Stack2_Backup_Authentication $authentication;
    private Stack2_Logger $logger;
    private string $site_id;
    private string $api_key;

    public function __construct(
        Stack2_SSO_Service $sso_service,
        Stack2_Backup_Authentication $authentication,
        Stack2_Logger $logger,
        string $site_id,
        string $api_key
    ) {
        $this->sso_service = $sso_service;
        $this->authentication = $authentication;
        $this->logger = $logger;
        $this->site_id = $site_id;
        $this->api_key = $api_key;
    }

    public function register_routes(): void
    {
        register_rest_route('stack2/v1', '/sso/users', array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => array($this, 'list_users'),
            'permission_callback' => '__return_true',
        ));

        register_rest_route('stack2/v1', '/sso/login', array(
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => array($this, 'login'),
            'permission_callback' => '__return_true',
        ));
    }

    public function list_users(WP_REST_Request $request): WP_REST_Response
    {
        $auth = $this->verify($request, 'GET', '/stack2/v1/sso/users', '');
        if (is_wp_error($auth)) {
            return $this->error_response_from_wp_error($auth);
        }

        return new WP_REST_Response(array(
            'success' => true,
            'error' => null,
            'users' => $this->sso_service->list_admin_users(),
        ), 200);
    }

    public function login(WP_REST_Request $request): WP_REST_Response
    {
        $raw_body = $request->get_body();
        $auth = $this->verify($request, 'POST', '/stack2/v1/sso/login', $raw_body);
        if (is_wp_error($auth)) {
            return $this->error_response_from_wp_error($auth);
        }

        $payload = json_decode($raw_body, true);
        if (!is_array($payload) || empty($payload['user_id'])) {
            return new WP_REST_Response(array(
                'success' => false,
                'error' => 'user_id is required.',
                'login_url' => null,
            ), 400);
        }

        $user_id = (int) $payload['user_id'];
        $token = $this->sso_service->create_login_token($user_id);

        if ($token === null) {
            return new WP_REST_Response(array(
                'success' => false,
                'error' => 'Requested user is not an eligible administrator.',
                'login_url' => null,
            ), 400);
        }

        return new WP_REST_Response(array(
            'success' => true,
            'error' => null,
            'login_url' => add_query_arg('stack2_sso_token', $token, home_url('/')),
        ), 200);
    }

    private function verify(WP_REST_Request $request, string $method, string $path, string $raw_body)
    {
        if ($this->site_id === '' || $this->api_key === '') {
            return new WP_Error('stack2_missing_credentials', 'Stack2 credentials are not configured.', array('status' => 503));
        }

        $result = $this->authentication->verify_request(
            $request,
            $this->site_id,
            $this->api_key,
            $method,
            $path,
            $raw_body
        );

        if (is_wp_error($result)) {
            $this->logger->error('SSO request rejected.', array('method' => $method, 'path' => $path));
        }

        return $result;
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
}
