<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Place and delete the same BatchPush restore PHP under the WordPress docroot.
 *
 * Platform uploads `stack2-{backupId}.php` (FTP BatchPush filename) and later
 * deletes it. This class does not implement restore steps or rewrite the script.
 */
class Stack2_Restore_Script_Store
{
    public const OPTION_STATE = 'stack2_restore_script';
    public const CRON_HOOK = 'stack2_restore_script_ttl';
    public const DEFAULT_TTL_SECONDS = 21600;
    public const MIN_TTL_SECONDS = 60;
    public const MAX_TTL_SECONDS = 86400;
    public const MAX_BYTES = 2097152;

    private string $docroot;
    private Stack2_Logger $logger;

    public function __construct(?string $docroot = null, ?Stack2_Logger $logger = null)
    {
        $this->docroot = wp_normalize_path(untrailingslashit($docroot ?? ABSPATH));
        $this->logger = $logger ?? new Stack2_Logger();
    }

    public function normalize_filename(?string $filename)
    {
        $raw = trim((string) $filename);
        if ($raw === '') {
            return new WP_Error('stack2_restore_filename_required', 'Restore script filename is required.', array('status' => 400));
        }

        $raw = str_replace('\\', '/', $raw);
        $raw = preg_replace('#^\./+#', '', $raw) ?? $raw;
        $raw = ltrim($raw, '/');

        if ($raw === '' || strpos($raw, '/') !== false || strpos($raw, '..') !== false) {
            return new WP_Error('stack2_restore_path_denied', 'Restore script path is not allowed.', array('status' => 400));
        }

        if (!preg_match('/^stack2-[A-Za-z0-9_-]{8,128}\.php$/', $raw)) {
            return new WP_Error('stack2_restore_path_denied', 'Restore script path is not allowed.', array('status' => 400));
        }

        return $raw;
    }

    public function resolve_path(string $filename)
    {
        $normalized = $this->normalize_filename($filename);
        if (is_wp_error($normalized)) {
            return $normalized;
        }

        $target = wp_normalize_path($this->docroot . '/' . $normalized);
        $root = $this->docroot . '/';
        if (strpos($target, $root) !== 0 || substr($target, strlen($root)) !== $normalized) {
            return new WP_Error('stack2_restore_path_denied', 'Restore script path is not allowed.', array('status' => 400));
        }

        return $target;
    }

    public function normalize_ttl($ttl_seconds): int
    {
        if ($ttl_seconds === null || $ttl_seconds === '') {
            return self::DEFAULT_TTL_SECONDS;
        }

        $ttl = (int) $ttl_seconds;
        if ($ttl < self::MIN_TTL_SECONDS) {
            return self::MIN_TTL_SECONDS;
        }
        if ($ttl > self::MAX_TTL_SECONDS) {
            return self::MAX_TTL_SECONDS;
        }

        return $ttl;
    }

    /**
     * @return array{filename: string, bytes: int, ttl_seconds: int, path: string}|WP_Error
     */
    public function place(string $filename, string $contents, $ttl_seconds = null, ?int $now = null)
    {
        $normalized = $this->normalize_filename($filename);
        if (is_wp_error($normalized)) {
            return $normalized;
        }

        $target = $this->resolve_path($normalized);
        if (is_wp_error($target)) {
            return $target;
        }

        if (!is_dir($this->docroot) || !is_writable($this->docroot)) {
            return new WP_Error('stack2_restore_not_ready', 'Connector is not ready to write the restore script.', array('status' => 503));
        }

        $bytes = strlen($contents);
        if ($bytes === 0) {
            return new WP_Error('stack2_restore_empty', 'Restore script body is required.', array('status' => 400));
        }
        if ($bytes > self::MAX_BYTES) {
            return new WP_Error('stack2_restore_too_large', 'Restore script exceeds the maximum allowed size.', array('status' => 413));
        }

        $body = preg_replace('/^\xEF\xBB\xBF/', '', $contents) ?? $contents;
        if (strpos(ltrim($body), '<?php') !== 0) {
            return new WP_Error('stack2_restore_not_php', 'Restore script must be PHP.', array('status' => 400));
        }

        $previous = $this->get_state();
        if (is_array($previous) && ($previous['filename'] ?? '') !== '' && $previous['filename'] !== $normalized) {
            $this->unlink_allowed((string) $previous['filename']);
        }

        if (!$this->write_atomically($target, $contents)) {
            $this->cleanup_temp_siblings($target);
            return new WP_Error('stack2_restore_write_failed', 'Failed to write restore script.', array('status' => 500));
        }

        $ttl = $this->normalize_ttl($ttl_seconds);
        $placed_at = $now ?? time();
        update_option(self::OPTION_STATE, array(
            'filename' => $normalized,
            'placed_at' => $placed_at,
            'ttl_seconds' => $ttl,
        ));
        $this->reschedule_ttl($ttl, $placed_at);

        $this->logger->info('Restore script placed.', array(
            'filename' => $normalized,
            'bytes' => $bytes,
            'ttl_seconds' => $ttl,
        ));

        return array(
            'filename' => $normalized,
            'bytes' => $bytes,
            'ttl_seconds' => $ttl,
            'path' => $normalized,
        );
    }

    /**
     * Idempotent delete. Missing file is success.
     *
     * @return array{filename: string|null, deleted: bool, already_gone: bool}|WP_Error
     */
    public function delete(?string $filename = null)
    {
        $state = $this->get_state();
        $requested = trim((string) $filename);

        if ($requested === '') {
            $requested = is_array($state) ? (string) ($state['filename'] ?? '') : '';
        }

        if ($requested === '') {
            $this->clear_state();
            return array(
                'filename' => null,
                'deleted' => false,
                'already_gone' => true,
            );
        }

        $normalized = $this->normalize_filename($requested);
        if (is_wp_error($normalized)) {
            return $normalized;
        }

        $existed = $this->unlink_allowed($normalized);
        if (is_array($state) && ($state['filename'] ?? '') === $normalized) {
            $this->clear_state();
        }

        $this->logger->info('Restore script deleted.', array(
            'filename' => $normalized,
            'already_gone' => !$existed,
        ));

        return array(
            'filename' => $normalized,
            'deleted' => $existed,
            'already_gone' => !$existed,
        );
    }

    public function expire_if_stale(?int $now = null): bool
    {
        $state = $this->get_state();
        if (!is_array($state) || ($state['filename'] ?? '') === '') {
            return false;
        }

        $filename = (string) $state['filename'];
        $target = $this->resolve_path($filename);
        if (is_wp_error($target)) {
            $this->clear_state();
            return false;
        }

        $now = $now ?? time();
        $ttl = $this->normalize_ttl($state['ttl_seconds'] ?? self::DEFAULT_TTL_SECONDS);
        $placed_at = (int) ($state['placed_at'] ?? 0);
        $mtime = is_file($target) ? (int) filemtime($target) : 0;
        $reference = $placed_at > 0 ? $placed_at : $mtime;

        if ($reference > 0 && ($now - $reference) < $ttl) {
            return false;
        }

        $this->unlink_allowed($filename);
        $this->clear_state();
        $this->logger->info('Restore script removed after TTL.', array(
            'filename' => $filename,
            'ttl_seconds' => $ttl,
        ));

        return true;
    }

    public function get_state(): ?array
    {
        $state = get_option(self::OPTION_STATE, null);
        return is_array($state) ? $state : null;
    }

    private function clear_state(): void
    {
        delete_option(self::OPTION_STATE);
        wp_clear_scheduled_hook(self::CRON_HOOK);
    }

    private function reschedule_ttl(int $ttl_seconds, int $placed_at): void
    {
        wp_clear_scheduled_hook(self::CRON_HOOK);
        wp_schedule_single_event($placed_at + $ttl_seconds, self::CRON_HOOK);
    }

    private function unlink_allowed(string $filename): bool
    {
        $target = $this->resolve_path($filename);
        if (is_wp_error($target)) {
            return false;
        }

        $this->cleanup_temp_siblings($target);
        if (!file_exists($target)) {
            return false;
        }

        return @unlink($target);
    }

    private function write_atomically(string $target, string $contents): bool
    {
        $tmp = $target . '.tmp.' . bin2hex(random_bytes(8));
        $written = @file_put_contents($tmp, $contents, LOCK_EX);
        if ($written === false || $written !== strlen($contents)) {
            @unlink($tmp);
            return false;
        }

        if (!@rename($tmp, $target)) {
            @unlink($tmp);
            return false;
        }

        return true;
    }

    private function cleanup_temp_siblings(string $target): void
    {
        $matches = glob($target . '.tmp.*');
        if (!is_array($matches)) {
            return;
        }

        foreach ($matches as $tmp) {
            if (is_file($tmp)) {
                @unlink($tmp);
            }
        }
    }
}
