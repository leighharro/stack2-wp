<?php

if (!defined('ABSPATH')) {
    exit;
}

class Stack2_Backup_Authentication
{
    private Stack2_Signature_Service $signature_service;

    public function __construct(Stack2_Signature_Service $signature_service)
    {
        $this->signature_service = $signature_service;
    }

    public function verify_request(
        WP_REST_Request $request,
        string $expected_site_id,
        string $api_key,
        string $method,
        string $path,
        string $raw_body = ''
    ) {
        $headers = array(
            'site_id' => (string) $request->get_header('x-stack2-site-id'),
            'timestamp' => (string) $request->get_header('x-stack2-timestamp'),
            'signature' => strtolower((string) $request->get_header('x-stack2-signature')),
        );

        return $this->signature_service->verify_signed_request(
            $headers,
            $raw_body,
            $expected_site_id,
            $api_key,
            $method,
            $path
        );
    }
}
