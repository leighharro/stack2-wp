<?php

use PHPUnit\Framework\TestCase;

abstract class BackupTestCase extends TestCase
{
    protected string $wp_root;
    protected string $backup_dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->wp_root = sys_get_temp_dir() . '/stack2-paged-' . uniqid('', true);
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

    protected function store(string $job_id, array $limits = array()): Stack2_Backup_Manifest_Store
    {
        $defaults = array(
            'chunk_file_limit' => 10000,
            'chunk_seconds' => 30,
            'index_file_limit' => 10000,
        );

        return new Stack2_Backup_Manifest_Store(
            $this->compressor(),
            $this->logger(),
            trailingslashit($this->backup_dir) . $job_id,
            $this->wp_root,
            array_merge($defaults, $limits)
        );
    }

    protected function manager(array $limits = array()): Stack2_Backup_Manager
    {
        $defaults = array(
            'chunk_file_limit' => 10000,
            'chunk_seconds' => 30,
            'index_file_limit' => 10000,
        );

        return new Stack2_Backup_Manager(
            $this->logger(),
            $this->compressor(),
            new Stack2_Database_Dumper($this->logger()),
            new Stack2_Backup_Manifest(),
            new Stack2_Backup_Cleaner(),
            $this->wp_root,
            $this->backup_dir,
            array_merge($defaults, $limits)
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

    protected function collect_all_pages(Stack2_Backup_Manifest_Store $store, int $limit = 50): array
    {
        $files = array();
        $cursor = null;

        for ($i = 0; $i < 1000; $i++) {
            $page = $store->get_page($cursor, $limit, true);
            if ((int) $page['http_status'] === 202) {
                $this->assertNull($page['payload']['next_cursor']);
                $this->assertTrue($page['payload']['has_more']);
                $this->assertFalse($page['payload']['manifest_complete']);
                $this->assertSame(array(), $page['payload']['files']);
                continue;
            }

            $this->assertSame(200, $page['http_status'], wp_json_encode($page['payload']));
            $payload = $page['payload'];
            $this->assertTrue($payload['success']);
            foreach ($payload['files'] as $file) {
                $files[] = $file;
            }

            if (empty($payload['has_more'])) {
                $this->assertNull($payload['next_cursor']);
                $this->assertTrue($payload['manifest_complete']);
                break;
            }

            $cursor = $payload['next_cursor'];
            $this->assertNotNull($cursor);
        }

        return $files;
    }

    protected function expected_files(array $relative_contents): array
    {
        $expected = array();
        foreach ($relative_contents as $relative => $contents) {
            if (strpos($relative, '/cache/') !== false || strpos($relative, '/.stack2-backup/') !== false) {
                continue;
            }
            $expected[$relative] = array(
                'path' => $relative,
                'sha256' => hash('sha256', $contents),
                'size' => strlen($contents),
            );
        }
        ksort($expected);
        return array_values($expected);
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
