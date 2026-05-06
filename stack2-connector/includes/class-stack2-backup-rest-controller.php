<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * REST controller that exposes a read-only backup status endpoint:
 *   GET /wp-json/stack2/v1/backup/{backup_id}/status
 *
 * The primary data flow is push-based: the plugin pushes each backup chunk
 * directly to the Stack2 API as it is generated.  This endpoint lets Stack2
 * verify the outcome after the backup command completes.
 *
 * Signing message format (GET, empty body):
 *   GET:/stack2/v1/backup/{backup_id}/status:{timestamp}:{sha256_of_empty_body}
 */
class Stack2_Backup_REST_Controller
{
    /** SHA-256 hash of an empty string – used for signing GET requests that have no body. */
    private const EMPTY_BODY_HASH = 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855';

    private Stack2_Backup_Service $backup_service;
    private Stack2_Signature_Service $signature_service;
    private Stack2_Logger $logger;
    private string $site_id;
    private string $api_key;

    public function __construct(
        Stack2_Backup_Service $backup_service,
        Stack2_Signature_Service $signature_service,
        Stack2_Logger $logger,
        string $site_id,
        string $api_key
    ) {
        $this->backup_service = $backup_service;
        $this->signature_service = $signature_service;
        $this->logger = $logger;
        $this->site_id = $site_id;
        $this->api_key = $api_key;
    }

    public function register_routes(): void
    {
        register_rest_route('stack2/v1', '/backup/(?P<backup_id>' . Stack2_Backup_Service::BACKUP_ID_RAW_REGEX . ')/status', array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => array($this, 'handle_status'),
            'permission_callback' => '__return_true',
            'args' => array(
                'backup_id' => array(
                    'required' => true,
                    'type' => 'string',
                    'pattern' => Stack2_Backup_Service::BACKUP_ID_RAW_REGEX,
                ),
            ),
        ));
    }

    /**
     * GET /wp-json/stack2/v1/backup/{backup_id}/status
     */
    public function handle_status(WP_REST_Request $request): WP_REST_Response
    {
        $backup_id = (string) $request->get_param('backup_id');
        $auth = $this->verify_request($request, '/stack2/v1/backup/' . $backup_id . '/status');
        if (is_wp_error($auth)) {
            return $this->error_response($auth);
        }

        $status = $this->backup_service->get_status($backup_id);

        if ($status === null) {
            return new WP_REST_Response(array(
                'success' => false,
                'error' => 'Backup not found.',
            ), 404);
        }

        return new WP_REST_Response(array_merge(array('success' => true), $status), 200);
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Verify HMAC signature headers for a GET request.
     *
     * @param WP_REST_Request $request
     * @param string          $route_path  e.g. "/stack2/v1/backup/bkp_.../status"
     * @return true|WP_Error
     */
    private function verify_request(WP_REST_Request $request, string $route_path)
    {
        if (empty($this->site_id) || empty($this->api_key)) {
            return new WP_Error('stack2_not_configured', 'Stack2 credentials are not configured.', array('status' => 503));
        }

        $site_id = (string) $request->get_header('x-stack2-site-id');
        $timestamp = (string) $request->get_header('x-stack2-timestamp');
        $signature = strtolower((string) $request->get_header('x-stack2-signature'));

        if ($site_id === '' || $timestamp === '' || $signature === '') {
            return new WP_Error('stack2_missing_headers', 'Missing required signature headers.', array('status' => 401));
        }

        if (!is_numeric($timestamp)) {
            return new WP_Error('stack2_invalid_timestamp', 'Invalid timestamp header.', array('status' => 401));
        }

        if (!hash_equals($this->site_id, $site_id)) {
            return new WP_Error('stack2_site_mismatch', 'Site ID mismatch.', array('status' => 401));
        }

        if (abs(time() - (int) $timestamp) > 300) {
            return new WP_Error('stack2_stale_timestamp', 'Timestamp outside allowed skew window.', array('status' => 401));
        }

        // GET requests have no body; use the sha256 of an empty string.
        $message = sprintf('GET:%s:%s:%s', $route_path, $timestamp, self::EMPTY_BODY_HASH);
        if (!$this->signature_service->verify($message, $signature, $this->api_key)) {
            $this->logger->error('Backup status request signature verification failed.', array('route' => $route_path));
            return new WP_Error('stack2_bad_signature', 'Signature verification failed.', array('status' => 401));
        }

        return true;
    }

    private function error_response(WP_Error $error): WP_REST_Response
    {
        $error_data = $error->get_error_data();
        $status = (int) (is_array($error_data) && isset($error_data['status']) ? $error_data['status'] : 401);
        return new WP_REST_Response(array(
            'success' => false,
            'error' => $error->get_error_message(),
        ), $status);
    }
}
