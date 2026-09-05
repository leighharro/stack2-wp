<?php

use PHPUnit\Framework\TestCase;

abstract class BackupTestCase extends TestCase
{
    protected string $wp_root;
    protected string $backup_dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->wp_root = sys_get_temp_dir() . '/stack2-agent-' . uniqid('', true);
        $this->backup_dir = $this->wp_root . '/wp-content/.stack2-backup';
        wp_mkdir_p($this->wp_root . '/wp-content/uploads');
        wp_mkdir_p($this->backup_dir);
        $GLOBALS['stack2_transients'] = array();
        $GLOBALS['stack2_cron'] = array();
    }

    protected function tearDown(): void
    {
        $this->delete_tree($this->wp_root);
        parent::tearDown();
    }

    protected function write_file(string $relative, string $contents): string
    {
        $absolute = trailingslashit($this->wp_root) . $relative;
        wp_mkdir_p(dirname($absolute));
        file_put_contents($absolute, $contents);
        return $absolute;
    }

    protected function create_tree(array $files): void
    {
        foreach ($files as $relative => $contents) {
            $this->write_file($relative, $contents);
        }
    }

    protected function logger(): Stack2_Logger
    {
        return new Stack2_Logger();
    }

    protected function compressor(): Stack2_Backup_Compressor
    {
        return new Stack2_Backup_Compressor($this->logger(), $this->wp_root);
    }

    protected function scanner(float $time_budget = 30.0): Stack2_Backup_File_Scanner
    {
        return new Stack2_Backup_File_Scanner($this->compressor(), $this->wp_root, $time_budget);
    }

    protected function manager(): Stack2_Backup_Manager
    {
        $compressor = $this->compressor();

        return new Stack2_Backup_Manager(
            $this->logger(),
            $compressor,
            new Stack2_Database_Dumper($this->logger()),
            new Stack2_Backup_Manifest(),
            new Stack2_Backup_Cleaner(),
            $this->wp_root,
            $this->backup_dir,
            new Stack2_Backup_File_Scanner($compressor, $this->wp_root)
        );
    }

    protected function api(Stack2_Backup_Manager $manager, string $site_id = 'site_test', string $api_key = 'secret-key'): Stack2_Backup_API
    {
        return new Stack2_Backup_API(
            $manager,
            new Stack2_Backup_Authentication(new Stack2_Signature_Service()),
            $this->logger(),
            $site_id,
            $api_key
        );
    }

    protected function sign_request(WP_REST_Request $request, string $method, string $path, string $body, string $api_key = 'secret-key', string $site_id = 'site_test'): void
    {
        $service = new Stack2_Signature_Service();
        $timestamp = (string) time();
        $hash = $service->sha256_hex($body);
        $signature = $service->sign($service->build_request_message($method, $path, $timestamp, $hash), $api_key);
        $request->set_header('x-stack2-site-id', $site_id);
        $request->set_header('x-stack2-timestamp', $timestamp);
        $request->set_header('x-stack2-signature', $signature);
        $request->set_body($body);
        $request->set_route($path);
    }

    /**
     * @return array<int, array>
     */
    protected function collect_all_scan_pages(Stack2_Backup_File_Scanner $scanner, int $limit = 50, bool $include_sha256 = false, bool $include_dirs = false): array
    {
        $entries = array();
        $cursor = '';

        for ($i = 0; $i < 1000; $i++) {
            $page = $scanner->scan($cursor, $limit, $include_sha256, $include_dirs);
            $this->assertIsArray($page['entries']);
            foreach ($page['entries'] as $entry) {
                $entries[] = $entry;
            }

            if (empty($page['has_more'])) {
                $this->assertNull($page['next_cursor']);
                break;
            }

            $this->assertNotNull($page['next_cursor']);
            $cursor = (string) $page['next_cursor'];
        }

        return $entries;
    }

    /**
     * @return array<int, array{path: string, size: int, mtime: int, sha256?: string}>
     */
    protected function expected_scan_entries(array $relative_contents, bool $include_sha256 = false): array
    {
        $expected = array();
        foreach ($relative_contents as $relative => $contents) {
            if ($this->is_excluded_test_path($relative)) {
                continue;
            }

            $absolute = trailingslashit($this->wp_root) . $relative;
            $entry = array(
                'path' => $relative,
                'size' => strlen($contents),
                'mtime' => (int) filemtime($absolute),
            );
            if ($include_sha256) {
                $entry['sha256'] = hash('sha256', $contents);
            }
            $expected[$relative] = $entry;
        }

        ksort($expected);
        return array_values($expected);
    }

    protected function sort_entries_by_path(array $entries): array
    {
        usort($entries, static function (array $left, array $right): int {
            return strcmp((string) $left['path'], (string) $right['path']);
        });

        return array_values($entries);
    }

    protected function is_excluded_test_path(string $relative): bool
    {
        $normalized = '/' . ltrim(wp_normalize_path($relative), '/');
        $needles = array(
            '/cache/',
            '/.stack2-backup/',
            '/wp-content/updraft/',
        );

        foreach ($needles as $needle) {
            if (strpos($normalized, $needle) !== false) {
                return true;
            }
        }

        return false;
    }

    private function delete_tree(string $path): void
    {
        if (!file_exists($path)) {
            return;
        }

        if (is_file($path) || is_link($path)) {
            @unlink($path);
            return;
        }

        $items = scandir($path);
        if (!is_array($items)) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $this->delete_tree($path . '/' . $item);
        }

        @rmdir($path);
    }
}
