<?php

use PHPUnit\Framework\TestCase;

class RestoreScriptApiTest extends TestCase
{
    private const SITE_ID = 'site_test';
    private const API_KEY = 'secret-key';
    private const FILENAME = 'stack2-550e8400-e29b-41d4-a716-446655440000.php';
    private const PHP_BODY = "<?php\n// stack2 restore fixture\n";

    private string $docroot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->docroot = sys_get_temp_dir() . '/stack2-restore-' . uniqid('', true);
        wp_mkdir_p($this->docroot);
        $GLOBALS['stack2_options'] = array(
            Stack2_Plugin::OPTION_BASE_URL => 'https://app.stack2.au',
            Stack2_Plugin::OPTION_SITE_ID => self::SITE_ID,
            Stack2_Plugin::OPTION_API_KEY => self::API_KEY,
        );
        $GLOBALS['stack2_transients'] = array();
        $GLOBALS['stack2_cron'] = array();
    }

    protected function tearDown(): void
    {
        $this->delete_tree($this->docroot);
        parent::tearDown();
    }

    public function test_place_then_delete_writes_and_removes_docroot_file(): void
    {
        $api = $this->api();
        $body = wp_json_encode(array(
            'filename' => self::FILENAME,
            'content' => self::PHP_BODY,
        ));

        $place = new WP_REST_Request();
        $place->set_method('PUT');
        $this->sign_request($place, 'PUT', '/wp-json/stack2/v1/restore-script', $body);

        $placed = $api->place_script($place);
        $data = $placed->get_data();

        $this->assertSame(200, $placed->get_status());
        $this->assertTrue($data['success']);
        $this->assertSame(self::FILENAME, $data['filename']);
        $this->assertSame(strlen(self::PHP_BODY), $data['bytes']);
        $this->assertSame(21600, $data['ttl_seconds']);
        $this->assertFileExists($this->docroot . '/' . self::FILENAME);
        $this->assertSame(self::PHP_BODY, file_get_contents($this->docroot . '/' . self::FILENAME));
        $this->assertSame(array(), glob($this->docroot . '/' . self::FILENAME . '.tmp.*') ?: array());

        $delete = new WP_REST_Request();
        $delete->set_method('DELETE');
        $delete_body = wp_json_encode(array('filename' => self::FILENAME));
        $this->sign_request($delete, 'DELETE', '/wp-json/stack2/v1/restore-script', $delete_body);

        $deleted = $api->delete_script($delete);
        $deleted_data = $deleted->get_data();

        $this->assertSame(200, $deleted->get_status());
        $this->assertTrue($deleted_data['success']);
        $this->assertTrue($deleted_data['deleted']);
        $this->assertFalse($deleted_data['already_gone']);
        $this->assertFileDoesNotExist($this->docroot . '/' . self::FILENAME);
    }

    public function test_delete_is_idempotent_when_file_already_gone(): void
    {
        $api = $this->api();
        $body = wp_json_encode(array('filename' => self::FILENAME));

        $request = new WP_REST_Request();
        $request->set_method('DELETE');
        $this->sign_request($request, 'DELETE', '/wp-json/stack2/v1/restore-script', $body);

        $response = $api->delete_script($request);
        $data = $response->get_data();

        $this->assertSame(200, $response->get_status());
        $this->assertTrue($data['success']);
        $this->assertTrue($data['already_gone']);
        $this->assertFalse($data['deleted']);
        $this->assertFileDoesNotExist($this->docroot . '/' . self::FILENAME);
    }

    public function test_disconnected_place_returns_503_and_writes_nothing(): void
    {
        $api = $this->api('', '');
        $body = wp_json_encode(array(
            'filename' => self::FILENAME,
            'content' => self::PHP_BODY,
        ));

        $request = new WP_REST_Request();
        $request->set_method('PUT');
        $this->sign_request($request, 'PUT', '/wp-json/stack2/v1/restore-script', $body);

        $response = $api->place_script($request);

        $this->assertSame(503, $response->get_status());
        $this->assertSame('Connector is disconnected or not ready.', $response->get_data()['error']);
        $this->assertFileDoesNotExist($this->docroot . '/' . self::FILENAME);
        $this->assertSame(array(), glob($this->docroot . '/stack2-*.php*') ?: array());
    }

    public function test_disconnected_delete_returns_503(): void
    {
        $store = new Stack2_Restore_Script_Store($this->docroot, new Stack2_Logger());
        $store->place(self::FILENAME, self::PHP_BODY);

        $api = $this->api('', '');
        $request = new WP_REST_Request();
        $request->set_method('DELETE');
        $this->sign_request($request, 'DELETE', '/wp-json/stack2/v1/restore-script', '');

        $response = $api->delete_script($request);

        $this->assertSame(503, $response->get_status());
        $this->assertFileExists($this->docroot . '/' . self::FILENAME);
    }

    public function test_unsafe_path_is_rejected_and_writes_nothing(): void
    {
        $api = $this->api();
        $unsafe = array(
            '../wp-config.php',
            'wp-config.php',
            'stack2-evil.txt',
            'stack2/../stack2-550e8400-e29b-41d4-a716-446655440000.php',
            'wp-admin/stack2-550e8400-e29b-41d4-a716-446655440000.php',
            'stack2-short.php',
        );

        foreach ($unsafe as $filename) {
            $body = wp_json_encode(array(
                'filename' => $filename,
                'content' => self::PHP_BODY,
            ));
            $request = new WP_REST_Request();
            $request->set_method('PUT');
            $this->sign_request($request, 'PUT', '/wp-json/stack2/v1/restore-script', $body);

            $response = $api->place_script($request);
            $this->assertSame(400, $response->get_status(), $filename);
            $this->assertFalse($response->get_data()['success'], $filename);
        }

        $this->assertSame(array(), array_values(array_filter(
            glob($this->docroot . '/{*,.[!.,]*}', GLOB_BRACE) ?: array()
        )));
    }

    public function test_ttl_expires_tracked_restore_script(): void
    {
        $store = new Stack2_Restore_Script_Store($this->docroot, new Stack2_Logger());
        $placed_at = 1_700_000_000;
        $result = $store->place(self::FILENAME, self::PHP_BODY, 60, $placed_at);

        $this->assertIsArray($result);
        $this->assertFileExists($this->docroot . '/' . self::FILENAME);
        $this->assertFalse($store->expire_if_stale($placed_at + 30));
        $this->assertFileExists($this->docroot . '/' . self::FILENAME);

        $this->assertTrue($store->expire_if_stale($placed_at + 61));
        $this->assertFileDoesNotExist($this->docroot . '/' . self::FILENAME);
        $this->assertNull($store->get_state());
    }

    public function test_bad_hmac_does_not_write_file(): void
    {
        $api = $this->api();
        $body = wp_json_encode(array(
            'filename' => self::FILENAME,
            'content' => self::PHP_BODY,
        ));

        $request = new WP_REST_Request();
        $request->set_method('PUT');
        $request->set_header('x-stack2-site-id', self::SITE_ID);
        $request->set_header('x-stack2-timestamp', (string) time());
        $request->set_header('x-stack2-signature', str_repeat('a', 64));
        $request->set_body($body);
        $request->set_route('/wp-json/stack2/v1/restore-script');

        $response = $api->place_script($request);

        $this->assertSame(401, $response->get_status());
        $this->assertFileDoesNotExist($this->docroot . '/' . self::FILENAME);
    }

    public function test_raw_php_body_place_uses_allowlisted_filename_param(): void
    {
        $api = $this->api();
        $request = new WP_REST_Request();
        $request->set_method('POST');
        $request->set_param('filename', self::FILENAME);
        $this->sign_request($request, 'POST', '/wp-json/stack2/v1/restore-script', self::PHP_BODY);

        $response = $api->place_script($request);

        $this->assertSame(200, $response->get_status());
        $this->assertSame(self::PHP_BODY, file_get_contents($this->docroot . '/' . self::FILENAME));
    }

    public function test_logger_redacts_restore_key_and_api_key(): void
    {
        $log_file = $this->docroot . '/php-error.log';
        $previous = ini_get('error_log');
        ini_set('log_errors', '1');
        ini_set('error_log', $log_file);
        update_option(Stack2_Logger::OPTION_DEBUG, true);

        try {
            (new Stack2_Logger())->error('auth dump', array(
                'api_key' => 'super-secret-api-key',
                'restore_key' => 'super-secret-restore-key',
                'filename' => self::FILENAME,
            ));
        } finally {
            ini_set('error_log', $previous);
        }

        $this->assertFileExists($log_file);
        $joined = (string) file_get_contents($log_file);
        $this->assertStringContainsString('[redacted]', $joined);
        $this->assertStringNotContainsString('super-secret-api-key', $joined);
        $this->assertStringNotContainsString('super-secret-restore-key', $joined);
        $this->assertStringContainsString(self::FILENAME, $joined);
    }

    public function test_disconnect_deletes_tracked_restore_script(): void
    {
        wp_mkdir_p(ABSPATH);
        $target = trailingslashit(wp_normalize_path(ABSPATH)) . self::FILENAME;
        $store = new Stack2_Restore_Script_Store(ABSPATH, new Stack2_Logger());
        $store->place(self::FILENAME, self::PHP_BODY);
        $this->assertFileExists($target);

        $executor = new Stack2_Command_Executor(
            new Stack2_Inventory_Collector(),
            new Stack2_Logger(),
            self::SITE_ID
        );
        $result = $executor->execute('disconnect', null, null);

        $this->assertTrue($result['success']);
        $this->assertFileDoesNotExist($target);
    }

    private function api(string $site_id = self::SITE_ID, string $api_key = self::API_KEY): Stack2_Restore_API
    {
        return new Stack2_Restore_API(
            new Stack2_Restore_Script_Store($this->docroot, new Stack2_Logger()),
            new Stack2_Backup_Authentication(new Stack2_Signature_Service()),
            new Stack2_Logger(),
            $site_id,
            $api_key
        );
    }

    private function sign_request(WP_REST_Request $request, string $method, string $path, string $body, string $api_key = self::API_KEY): void
    {
        $service = new Stack2_Signature_Service();
        $timestamp = (string) time();
        $hash = $service->sha256_hex($body);
        $signature = $service->sign($service->build_request_message($method, $path, $timestamp, $hash), $api_key);
        $request->set_header('x-stack2-site-id', self::SITE_ID);
        $request->set_header('x-stack2-timestamp', $timestamp);
        $request->set_header('x-stack2-signature', $signature);
        $request->set_body($body);
        $request->set_route($path);
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
