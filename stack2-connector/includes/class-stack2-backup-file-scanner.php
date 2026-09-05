<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Stateless WordPress-root file inventory for Platform-driven backups.
 *
 * Scan is a bounded DFS walk with a path+index stack cursor (no on-disk
 * ledger, no count-skip from root). Checksums are optional on scan and
 * intended to be fetched in batches via stats().
 */
class Stack2_Backup_File_Scanner
{
    public const DEFAULT_SCAN_LIMIT = 500;
    public const MAX_SCAN_LIMIT = 2000;
    public const DEFAULT_STATS_BATCH = 50;
    public const MAX_STATS_BATCH = 200;
    public const SOFT_TIME_BUDGET = 12.0;

    private Stack2_Backup_Compressor $compressor;
    private string $wordpress_root;
    private float $time_budget_seconds;

    public function __construct(
        Stack2_Backup_Compressor $compressor,
        ?string $wordpress_root = null,
        float $time_budget_seconds = self::SOFT_TIME_BUDGET
    ) {
        $this->compressor = $compressor;
        $this->wordpress_root = $wordpress_root !== null && $wordpress_root !== ''
            ? $wordpress_root
            : (defined('ABSPATH') ? (string) ABSPATH : '');
        $this->time_budget_seconds = max(0.1, $time_budget_seconds);
    }

    public static function inventory_limits(): array
    {
        return array(
            'scan' => array(
                'default_limit' => self::DEFAULT_SCAN_LIMIT,
                'max_limit' => self::MAX_SCAN_LIMIT,
            ),
            'stats' => array(
                'default_batch' => self::DEFAULT_STATS_BATCH,
                'max_batch' => self::MAX_STATS_BATCH,
            ),
        );
    }

    public static function normalize_scan_limit($limit): int
    {
        $value = (int) $limit;
        if ($value <= 0) {
            return self::DEFAULT_SCAN_LIMIT;
        }

        if ($value > self::MAX_SCAN_LIMIT) {
            return self::MAX_SCAN_LIMIT;
        }

        return $value;
    }

    /**
     * @return array{entries: array<int, array>, next_cursor: ?string, has_more: bool, scanned: int}
     */
    public function scan(?string $cursor, $limit, bool $include_sha256 = false, bool $include_dirs = false): array
    {
        $limit = self::normalize_scan_limit($limit);
        $stack = $this->decode_cursor($cursor);
        $entries = array();
        $scanned = 0;
        $deadline = microtime(true) + $this->time_budget_seconds;
        $root = $this->root();

        if (!is_dir($root)) {
            return array(
                'entries' => array(),
                'next_cursor' => null,
                'has_more' => false,
                'scanned' => 0,
            );
        }

        while ($stack !== array()) {
            if (count($entries) >= $limit || microtime(true) >= $deadline) {
                break;
            }

            $frame = array_pop($stack);
            $rel_dir = (string) ($frame['p'] ?? '');
            $index = (int) ($frame['i'] ?? 0);
            $abs_dir = $rel_dir === '' ? $root : $root . $rel_dir;

            if (!is_dir($abs_dir) || is_link($abs_dir)) {
                continue;
            }

            $children = $this->list_children($abs_dir);
            $total = count($children);

            for ($i = $index; $i < $total; $i++) {
                if (count($entries) >= $limit || microtime(true) >= $deadline) {
                    $stack[] = array('p' => $rel_dir, 'i' => $i);
                    break 2;
                }

                $name = $children[$i];
                $relative = $rel_dir === '' ? $name : $rel_dir . '/' . $name;
                $absolute = $root . $relative;
                $scanned++;

                if (is_dir($absolute) && !is_link($absolute)) {
                    if ($this->compressor->is_excluded($absolute, true)) {
                        continue;
                    }

                    if ($include_dirs) {
                        $dir_entry = $this->directory_entry($relative, $absolute);
                        if ($dir_entry !== null) {
                            $entries[] = $dir_entry;
                        }
                    }

                    if ($i + 1 < $total) {
                        $stack[] = array('p' => $rel_dir, 'i' => $i + 1);
                    }
                    $stack[] = array('p' => $relative, 'i' => 0);
                    continue 2;
                }

                if (!is_file($absolute)) {
                    continue;
                }

                if ($this->compressor->is_excluded($absolute, false)) {
                    continue;
                }

                $entry = $this->file_entry($relative, $absolute, $include_sha256);
                if ($entry !== null) {
                    $entries[] = $entry;
                }
            }
        }

        $has_more = $stack !== array();

        return array(
            'entries' => $entries,
            'next_cursor' => $has_more ? $this->encode_cursor($stack) : null,
            'has_more' => $has_more,
            'scanned' => $scanned,
        );
    }

    /**
     * @param array<int, mixed> $paths
     * @return array{stats: array<int, array>, missing: array<int, string>, failed: array<int, array{path: string, error: string}>}
     */
    public function stats(array $paths, bool $include_sha256 = true): array
    {
        if (count($paths) > self::MAX_STATS_BATCH) {
            throw new InvalidArgumentException(
                'Too many paths. Maximum is ' . self::MAX_STATS_BATCH . '.'
            );
        }

        $stats = array();
        $missing = array();
        $failed = array();
        $root = $this->root();

        foreach ($paths as $raw) {
            if (!is_string($raw)) {
                $failed[] = array(
                    'path' => is_scalar($raw) ? (string) $raw : '',
                    'error' => 'Path must be a string.',
                );
                continue;
            }

            $relative = $this->sanitize_relative_path($raw);
            if ($relative === '') {
                $failed[] = array(
                    'path' => $raw,
                    'error' => 'Invalid path.',
                );
                continue;
            }

            $absolute = $root . $relative;
            $normalized_absolute = wp_normalize_path($absolute);
            if (strpos($normalized_absolute, $root) !== 0) {
                $failed[] = array(
                    'path' => $relative,
                    'error' => 'Path is outside the WordPress root.',
                );
                continue;
            }

            if ($this->compressor->is_excluded($absolute, is_dir($absolute))) {
                $missing[] = $relative;
                continue;
            }

            if (!file_exists($absolute)) {
                $missing[] = $relative;
                continue;
            }

            if (!is_file($absolute)) {
                $failed[] = array(
                    'path' => $relative,
                    'error' => 'Path is not a file.',
                );
                continue;
            }

            if (!is_readable($absolute)) {
                $failed[] = array(
                    'path' => $relative,
                    'error' => 'File is not readable.',
                );
                continue;
            }

            $size = @filesize($absolute);
            $mtime = @filemtime($absolute);
            if ($size === false || $mtime === false) {
                $failed[] = array(
                    'path' => $relative,
                    'error' => 'Unable to read file metadata.',
                );
                continue;
            }

            $entry = array(
                'path' => $relative,
                'size' => (int) $size,
                'mtime' => (int) $mtime,
            );

            if ($include_sha256) {
                $sha256 = $this->compressor->checksum_for_path($absolute);
                if ($sha256 === '') {
                    $failed[] = array(
                        'path' => $relative,
                        'error' => 'Unable to compute sha256.',
                    );
                    continue;
                }
                $entry['sha256'] = strtolower($sha256);
            }

            $stats[] = $entry;
        }

        return array(
            'stats' => $stats,
            'missing' => $missing,
            'failed' => $failed,
        );
    }

    /**
     * @return array<int, array{p: string, i: int}>
     */
    public function decode_cursor(?string $cursor): array
    {
        if ($cursor === null || $cursor === '') {
            return array(array('p' => '', 'i' => 0));
        }

        $value = trim($cursor);
        if ($value === '') {
            return array(array('p' => '', 'i' => 0));
        }

        $base64 = strtr($value, '-_', '+/');
        $padding = strlen($base64) % 4;
        if ($padding > 0) {
            $base64 .= str_repeat('=', 4 - $padding);
        }

        $decoded = base64_decode($base64, true);
        if ($decoded === false) {
            throw new InvalidArgumentException('Invalid scan cursor.');
        }

        $payload = json_decode($decoded, true);
        if (!is_array($payload) || (int) ($payload['v'] ?? 0) !== 1 || !isset($payload['s']) || !is_array($payload['s'])) {
            throw new InvalidArgumentException('Invalid scan cursor.');
        }

        $stack = array();
        foreach ($payload['s'] as $frame) {
            if (!is_array($frame) || !isset($frame['p'], $frame['i']) || !is_string($frame['p']) || !is_numeric($frame['i'])) {
                throw new InvalidArgumentException('Invalid scan cursor.');
            }

            $index = (int) $frame['i'];
            if ($index < 0) {
                throw new InvalidArgumentException('Invalid scan cursor.');
            }

            $path = $this->sanitize_relative_path((string) $frame['p']);
            if ($frame['p'] !== '' && $path === '') {
                throw new InvalidArgumentException('Invalid scan cursor.');
            }

            $stack[] = array(
                'p' => $frame['p'] === '' ? '' : $path,
                'i' => $index,
            );
        }

        return $stack;
    }

    /**
     * @param array<int, array{p: string, i: int}> $stack
     */
    public function encode_cursor(array $stack): string
    {
        $json = wp_json_encode(array('v' => 1, 's' => array_values($stack)), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new RuntimeException('Failed to encode scan cursor.');
        }

        return rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
    }

    private function file_entry(string $relative, string $absolute, bool $include_sha256): ?array
    {
        $size = @filesize($absolute);
        $mtime = @filemtime($absolute);
        if ($size === false || $mtime === false || !is_readable($absolute)) {
            return null;
        }

        $entry = array(
            'path' => $relative,
            'size' => (int) $size,
            'mtime' => (int) $mtime,
        );

        if ($include_sha256) {
            $sha256 = $this->compressor->checksum_for_path($absolute);
            if ($sha256 === '') {
                return null;
            }
            $entry['sha256'] = strtolower($sha256);
        }

        return $entry;
    }

    private function directory_entry(string $relative, string $absolute): ?array
    {
        $mtime = @filemtime($absolute);
        if ($mtime === false) {
            return null;
        }

        return array(
            'path' => $relative,
            'size' => 0,
            'mtime' => (int) $mtime,
        );
    }

    private function list_children(string $absolute_dir): array
    {
        $entries = @scandir($absolute_dir);
        if (!is_array($entries)) {
            return array();
        }

        $children = array();
        foreach ($entries as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $children[] = $name;
        }

        sort($children, SORT_STRING);

        return $children;
    }

    private function sanitize_relative_path(string $relative_path): string
    {
        $path = trim($relative_path);
        if ($path === '' || strpos($path, "\0") !== false) {
            return '';
        }

        $path = wp_normalize_path($path);
        $path = ltrim($path, '/');
        $path = preg_replace('#^\./+#', '', $path);

        if ($path === '' || strpos($path, '../') !== false || $path === '..') {
            return '';
        }

        return $path;
    }

    private function root(): string
    {
        return trailingslashit(wp_normalize_path($this->wordpress_root));
    }
}
