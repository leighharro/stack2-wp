<?php

if (!defined('ABSPATH')) {
    exit;
}

class Stack2_Signature_Service
{
    private const ALLOWED_SKEW_SECONDS = 300;

    public function sha256_hex(string $raw_body): string
    {
        return hash('sha256', $raw_body);
    }

    public function build_push_message(string $timestamp, string $body_hash): string
    {
        return sprintf('POST:stack2-push:%s:%s', $timestamp, $body_hash);
    }

    public function build_command_message(string $timestamp, string $body_hash): string
    {
        return sprintf('POST:/stack2/v1/command:%s:%s', $timestamp, $body_hash);
    }

    public function build_request_message(string $method, string $path, string $timestamp, string $body_hash): string
    {
        return sprintf('%s:%s:%s:%s', strtoupper($method), $path, $timestamp, $body_hash);
    }

    public function sign(string $message, string $api_key): string
    {
        return hash_hmac('sha256', $message, $api_key);
    }

    public function verify(string $message, string $signature, string $api_key): bool
    {
        $expected = $this->sign($message, $api_key);
        return hash_equals($expected, strtolower($signature));
    }

    public function verify_command_request(array $headers, string $raw_body, string $expected_site_id, string $api_key)
    {
        return $this->verify_signed_request(
            $headers,
            $raw_body,
            $expected_site_id,
            $api_key,
            'POST',
            '/stack2/v1/command'
        );
    }

    public function verify_signed_request(
        array $headers,
        string $raw_body,
        string $expected_site_id,
        string $api_key,
        string $method,
        string $path
    )
    {
        $site_id = $headers['site_id'] ?? '';
        $timestamp = $headers['timestamp'] ?? '';
        $signature = $headers['signature'] ?? '';

        if ($site_id === '' || $timestamp === '' || $signature === '') {
            return new WP_Error('stack2_missing_headers', 'Missing required signature headers.', array('status' => 401));
        }

        if (!is_numeric($timestamp)) {
            return new WP_Error('stack2_invalid_timestamp', 'Invalid timestamp header.', array('status' => 401));
        }

        if (!hash_equals($expected_site_id, $site_id)) {
            return new WP_Error('stack2_site_mismatch', 'Site ID mismatch.', array('status' => 401));
        }

        if (abs(time() - (int) $timestamp) > self::ALLOWED_SKEW_SECONDS) {
            return new WP_Error('stack2_stale_timestamp', 'Timestamp out of tolerance (> 300s)', array('status' => 401));
        }

        $body_hash = $this->sha256_hex($raw_body);
        $message = $this->build_request_message($method, $path, (string) $timestamp, $body_hash);
        if (!$this->verify($message, $signature, $api_key)) {
            return new WP_Error('stack2_bad_signature', 'Invalid HMAC signature', array('status' => 401));
        }

        return true;
    }
}
