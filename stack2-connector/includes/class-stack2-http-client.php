<?php

if (!defined('ABSPATH')) {
    exit;
}

class Stack2_Http_Client
{
    private Stack2_Signature_Service $signature_service;

    public function __construct(Stack2_Signature_Service $signature_service)
    {
        $this->signature_service = $signature_service;
    }

    public function push_inventory(array $inventory, string $base_url, string $site_id, string $api_key): array
    {
        $body = wp_json_encode($inventory, JSON_UNESCAPED_SLASHES);
        if (!is_string($body)) {
            return array(
                'success' => false,
                'status' => 0,
                'retryable' => false,
                'error' => 'Failed to encode inventory payload.',
            );
        }

        $timestamp = (string) time();
        $body_hash = $this->signature_service->sha256_hex($body);
        $message = $this->signature_service->build_push_message($timestamp, $body_hash);
        $signature = $this->signature_service->sign($message, $api_key);

        $response = wp_remote_post(untrailingslashit($base_url) . '/api/websites/plugin-inventory', array(
            'timeout' => 20,
            'headers' => array(
                'Content-Type' => 'application/json',
                'X-Stack2-Site-ID' => $site_id,
                'X-Stack2-Timestamp' => $timestamp,
                'X-Stack2-Signature' => $signature,
            ),
            'body' => $body,
        ));

        if (is_wp_error($response)) {
            return array(
                'success' => false,
                'status' => 0,
                'retryable' => true,
                'error' => $response->get_error_message(),
            );
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        $response_body = (string) wp_remote_retrieve_body($response);

        if ($status >= 200 && $status < 300) {
            return array(
                'success' => true,
                'status' => $status,
                'retryable' => false,
                'error' => null,
                'response_body' => $response_body,
            );
        }

        $retryable = $status >= 500;
        if ($status === 401 || $status === 403) {
            $retryable = false;
        }

        return array(
            'success' => false,
            'status' => $status,
            'retryable' => $retryable,
            'error' => sprintf('Stack2 API returned %d.', $status),
            'response_body' => $response_body,
        );
    }

    public function push_backup_chunk(string $base_url, string $site_id, string $api_key, array $chunk_data): array
    {
        $body = wp_json_encode($chunk_data, JSON_UNESCAPED_SLASHES);
        if (!is_string($body)) {
            return array(
                'success' => false,
                'status' => 0,
                'retryable' => false,
                'error' => 'Failed to encode backup chunk payload.',
            );
        }

        $timestamp = (string) time();
        $body_hash = $this->signature_service->sha256_hex($body);
        $message = sprintf('POST:stack2-backup-chunk:%s:%s', $timestamp, $body_hash);
        $signature = $this->signature_service->sign($message, $api_key);

        $response = wp_remote_post(untrailingslashit($base_url) . '/api/websites/backup/chunk', array(
            'timeout' => 60,
            'headers' => array(
                'Content-Type' => 'application/json',
                'X-Stack2-Site-ID' => $site_id,
                'X-Stack2-Timestamp' => $timestamp,
                'X-Stack2-Signature' => $signature,
            ),
            'body' => $body,
        ));

        if (is_wp_error($response)) {
            return array(
                'success' => false,
                'status' => 0,
                'retryable' => true,
                'error' => $response->get_error_message(),
            );
        }

        $status = (int) wp_remote_retrieve_response_code($response);

        if ($status >= 200 && $status < 300) {
            return array(
                'success' => true,
                'status' => $status,
                'retryable' => false,
                'error' => null,
            );
        }

        return array(
            'success' => false,
            'status' => $status,
            'retryable' => $status >= 500,
            'error' => sprintf('Stack2 API returned %d for backup chunk.', $status),
        );
    }
}
