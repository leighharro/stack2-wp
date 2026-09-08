<?php

use PHPUnit\Framework\TestCase;

class ForceUpdateCheckTest extends TestCase
{
    private const SITE_ID = 'site_test';
    private const API_KEY = 'secret-key';

    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['stack2_options'] = array(
            Stack2_Plugin::OPTION_BASE_URL => 'https://app.stack2.au',
            Stack2_Plugin::OPTION_SITE_ID => self::SITE_ID,
            Stack2_Plugin::OPTION_API_KEY => self::API_KEY,
        );
        $GLOBALS['stack2_transients'] = array(
            Stack2_Update_Checker::CACHE_TRANSIENT => array(
                'version' => '1.0.0',
                'package' => 'https://example.com/old.zip',
            ),
            'site_update_plugins' => (object) array('checked' => array()),
        );
        $GLOBALS['stack2_cron'] = array();
        $GLOBALS['stack2_wp_update_plugins_calls'] = 0;
        $GLOBALS['stack2_http_get'] = null;
    }

    protected function tearDown(): void
    {
        $GLOBALS['stack2_http_get'] = null;
        $GLOBALS['stack2_wp_update_plugins_calls'] = 0;
        parent::tearDown();
    }

    public function test_force_check_happy_path_update_available(): void
    {
        $this->stub_latest_release('1.2.0');

        $result = (new Stack2_Update_Checker(new Stack2_Logger()))->force_check();

        $this->assertTrue($result['success']);
        $this->assertNull($result['error']);
        $this->assertSame('update_available', $result['status']);
        $this->assertSame(STACK2_CONNECTOR_VERSION, $result['installed_version']);
        $this->assertSame('1.2.0', $result['available_version']);
        $this->assertTrue($result['has_update']);
        $this->assertSame(1, $GLOBALS['stack2_wp_update_plugins_calls']);
        $this->assertArrayNotHasKey('site_update_plugins', $GLOBALS['stack2_transients']);
    }

    public function test_force_check_up_to_date(): void
    {
        $this->stub_latest_release(STACK2_CONNECTOR_VERSION);

        $result = (new Stack2_Update_Checker(new Stack2_Logger()))->force_check();

        $this->assertTrue($result['success']);
        $this->assertSame('up_to_date', $result['status']);
        $this->assertSame(STACK2_CONNECTOR_VERSION, $result['installed_version']);
        $this->assertSame(STACK2_CONNECTOR_VERSION, $result['available_version']);
        $this->assertFalse($result['has_update']);
    }

    public function test_force_check_failure_shape(): void
    {
        $GLOBALS['stack2_http_get'] = static function () {
            return new WP_Error('http_request_failed', 'GitHub timeout');
        };

        $result = (new Stack2_Update_Checker(new Stack2_Logger()))->force_check();

        $this->assertFalse($result['success']);
        $this->assertSame('Unable to reach GitHub for release information.', $result['error']);
        $this->assertSame('check_failed', $result['status']);
        $this->assertSame(STACK2_CONNECTOR_VERSION, $result['installed_version']);
        $this->assertNull($result['available_version']);
        $this->assertFalse($result['has_update']);
        $this->assertSame(1, $GLOBALS['stack2_wp_update_plugins_calls']);
    }

    public function test_signed_check_updates_command_returns_versions(): void
    {
        $this->stub_latest_release('9.9.9');

        $controller = $this->controller();
        $body = wp_json_encode(array('action' => 'check_updates'));
        $request = new WP_REST_Request();
        $this->sign_command($request, $body);

        $response = $controller->handle_command($request);
        $data = $response->get_data();

        $this->assertSame(200, $response->get_status());
        $this->assertTrue($data['success']);
        $this->assertNull($data['error']);
        $this->assertNull($data['inventory']);
        $this->assertSame('update_available', $data['status']);
        $this->assertSame(STACK2_CONNECTOR_VERSION, $data['installed_version']);
        $this->assertSame('9.9.9', $data['available_version']);
    }

    public function test_signed_check_updates_command_failure_shape(): void
    {
        $GLOBALS['stack2_http_get'] = static function () {
            return array(
                'response' => array('code' => 502),
                'body' => 'bad gateway',
            );
        };

        $controller = $this->controller();
        $body = wp_json_encode(array('action' => 'check_updates'));
        $request = new WP_REST_Request();
        $this->sign_command($request, $body);

        $response = $controller->handle_command($request);
        $data = $response->get_data();

        $this->assertSame(502, $response->get_status());
        $this->assertFalse($data['success']);
        $this->assertSame('Unable to reach GitHub for release information.', $data['error']);
        $this->assertNull($data['inventory']);
        $this->assertSame('check_failed', $data['status']);
        $this->assertSame(STACK2_CONNECTOR_VERSION, $data['installed_version']);
        $this->assertNull($data['available_version']);
    }

    public function test_check_updates_requires_connection(): void
    {
        $controller = new Stack2_REST_Controller(
            new Stack2_Signature_Service(),
            $this->executor(),
            new Stack2_Logger(),
            '',
            ''
        );
        $body = wp_json_encode(array('action' => 'check_updates'));
        $request = new WP_REST_Request();
        $this->sign_command($request, $body);

        $response = $controller->handle_command($request);
        $this->assertSame(503, $response->get_status());
        $this->assertSame('Stack2 credentials are not configured.', $response->get_data()['error']);
    }

    public function test_check_updates_rejects_bad_hmac(): void
    {
        $controller = $this->controller();
        $body = wp_json_encode(array('action' => 'check_updates'));
        $request = new WP_REST_Request();
        $this->sign_command($request, $body, 'wrong-key');

        $response = $controller->handle_command($request);
        $this->assertSame(401, $response->get_status());
        $this->assertFalse($response->get_data()['success']);
    }

    private function stub_latest_release(string $version): void
    {
        $GLOBALS['stack2_http_get'] = static function () use ($version) {
            return array(
                'response' => array('code' => 200),
                'body' => wp_json_encode(array(
                    'tag_name' => 'v' . $version,
                    'html_url' => 'https://github.com/leighharro/stack2-wp/releases/tag/v' . $version,
                    'body' => 'notes',
                    'assets' => array(
                        array(
                            'name' => 'stack2-connector-' . $version . '.zip',
                            'browser_download_url' => 'https://example.com/stack2-connector-' . $version . '.zip',
                        ),
                    ),
                )),
            );
        };
    }

    private function executor(): Stack2_Command_Executor
    {
        return new Stack2_Command_Executor(
            new Stack2_Inventory_Collector(),
            new Stack2_Logger(),
            self::SITE_ID,
            new Stack2_Update_Checker(new Stack2_Logger())
        );
    }

    private function controller(): Stack2_REST_Controller
    {
        return new Stack2_REST_Controller(
            new Stack2_Signature_Service(),
            $this->executor(),
            new Stack2_Logger(),
            self::SITE_ID,
            self::API_KEY
        );
    }

    private function sign_command(WP_REST_Request $request, string $body, string $api_key = self::API_KEY): void
    {
        $service = new Stack2_Signature_Service();
        $timestamp = (string) time();
        $hash = $service->sha256_hex($body);
        $signature = $service->sign(
            $service->build_command_message($timestamp, $hash),
            $api_key
        );
        $request->set_header('x-stack2-site-id', self::SITE_ID);
        $request->set_header('x-stack2-timestamp', $timestamp);
        $request->set_header('x-stack2-signature', $signature);
        $request->set_body($body);
    }
}
