<?php

if (!defined('ABSPATH')) {
    exit;
}

class Stack2_REST_Controller
{
    private Stack2_Signature_Service $signature_service;
    private Stack2_Command_Executor $command_executor;
    private Stack2_Logger $logger;
    private string $site_id;
    private string $api_key;

    public function __construct(
        Stack2_Signature_Service $signature_service,
        Stack2_Command_Executor $command_executor,
        Stack2_Logger $logger,
        string $site_id,
        string $api_key
    ) {
        $this->signature_service = $signature_service;
        $this->command_executor = $command_executor;
        $this->logger = $logger;
        $this->site_id = $site_id;
        $this->api_key = $api_key;
    }

    public function register_routes(): void
    {
        register_rest_route('stack2/v1', '/command', array(
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => array($this, 'handle_command'),
            'permission_callback' => '__return_true',
        ));
    }

    public function handle_command(WP_REST_Request $request): WP_REST_Response
    {
        if (empty($this->site_id) || empty($this->api_key)) {
            return new WP_REST_Response(array(
                'success' => false,
                'error' => 'Stack2 credentials are not configured.',
                'inventory' => null,
            ), 503);
        }

        $raw_body = $request->get_body();
        $headers = array(
            'site_id' => (string) $request->get_header('x-stack2-site-id'),
            'timestamp' => (string) $request->get_header('x-stack2-timestamp'),
            'signature' => strtolower((string) $request->get_header('x-stack2-signature')),
        );

        $verified = $this->signature_service->verify_command_request($headers, $raw_body, $this->site_id, $this->api_key);
        if (is_wp_error($verified)) {
            $error_data = $verified->get_error_data();
            $status = (int) (is_array($error_data) && isset($error_data['status']) ? $error_data['status'] : 401);
            return new WP_REST_Response(array(
                'success' => false,
                'error' => $verified->get_error_message(),
                'inventory' => null,
            ), $status);
        }

        $payload = json_decode($raw_body, true);
        if (!is_array($payload)) {
            return new WP_REST_Response(array(
                'success' => false,
                'error' => 'Invalid JSON payload.',
                'inventory' => null,
            ), 400);
        }

        $action = isset($payload['action']) ? sanitize_key((string) $payload['action']) : '';
        $plugin_file = isset($payload['plugin']) ? sanitize_text_field((string) $payload['plugin']) : null;
        $slug = isset($payload['slug']) ? sanitize_title((string) $payload['slug']) : null;

        $allowed = array('install', 'update', 'activate', 'deactivate', 'delete', 'inventory', 'disconnect');
        if (!in_array($action, $allowed, true)) {
            return new WP_REST_Response(array(
                'success' => false,
                'error' => 'Unsupported action.',
                'inventory' => null,
            ), 400);
        }

        $result = $this->command_executor->execute($action, $plugin_file, $slug);
        $status = $result['success'] ? 200 : 400;

        if (!$result['success']) {
            $this->logger->error('Command rejected.', array('action' => $action, 'error' => $result['error']));
        }

        return new WP_REST_Response(array(
            'success' => (bool) $result['success'],
            'error' => $result['error'],
            'inventory' => $result['inventory'] ?? null,
        ), $status);
    }
}
