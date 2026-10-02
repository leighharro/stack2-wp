<?php

if (!defined('ABSPATH')) {
    exit;
}

class Stack2_Logger
{
    public const OPTION_DEBUG = 'stack2_debug_enabled';

    public function debug(string $message, array $context = array()): void
    {
        $this->log('debug', $message, $context);
    }

    public function info(string $message, array $context = array()): void
    {
        $this->log('info', $message, $context);
    }

    public function error(string $message, array $context = array()): void
    {
        $this->log('error', $message, $context);
    }

    public function log(string $level, string $message, array $context = array()): void
    {
        if ($level === 'debug' && !$this->is_debug_enabled()) {
            return;
        }

        $safe_context = $this->sanitize_context($context);
        $message = $this->redact_package_urls($message);
        $suffix = !empty($safe_context) ? ' ' . wp_json_encode($safe_context) : '';
        error_log(sprintf('STACK2_PLUGIN [%s] %s%s', strtoupper($level), $message, $suffix));
    }

    private function is_debug_enabled(): bool
    {
        return (bool) get_option(self::OPTION_DEBUG, false);
    }

    private function sanitize_context(array $context): array
    {
        $sensitive = array(
            'api_key',
            'signature',
            'authorization',
            'x-stack2-signature',
            'restore_key',
            'restorekey',
            'x-stack2-restore-key',
            'token',
            'password',
        );

        foreach ($context as $key => $value) {
            if (in_array(strtolower((string) $key), $sensitive, true)) {
                $context[$key] = '[redacted]';
                continue;
            }

            if (is_string($value)) {
                $context[$key] = $this->redact_package_urls($value);
                continue;
            }

            if (is_array($value)) {
                $context[$key] = $this->sanitize_context($value);
            }
        }

        return $context;
    }

    /**
     * Core package URLs stay out of the command log. The response may still
     * carry Automatic_Upgrader_Skin text.
     */
    private function redact_package_urls(string $text): string
    {
        $redacted = preg_replace(
            '#https?://(?:downloads\.wordpress\.org|downloads\.w\.org)(?:/[^\s\'"<>]*)?#i',
            '[package-url]',
            $text
        );

        return is_string($redacted) ? $redacted : $text;
    }
}
