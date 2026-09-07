<?php

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/stack2-connector/includes/class-stack2-inventory-collector.php';
require_once dirname(__DIR__, 2) . '/stack2-connector/includes/class-stack2-command-executor.php';
require_once dirname(__DIR__, 2) . '/stack2-connector/includes/class-stack2-rest-controller.php';
require_once dirname(__DIR__, 2) . '/stack2-connector/includes/class-stack2-plugin.php';

class CommandDisconnectTest extends TestCase
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
            Stack2_Plugin::OPTION_LAST_SYNC_STATUS => 'success',
        );
        $GLOBALS['stack2_transients'] = array(
            'stack2_sync_lock' => '1',
        );
        $GLOBALS['stack2_cron'] = array();
        wp_schedule_event(time() + 30, 'stack2_custom', Stack2_Plugin::CRON_HOOK_SYNC, array(0, 'cron'));
        wp_schedule_single_event(time() + 5, Stack2_Plugin::CRON_HOOK_SYNC, array(1, 'retry'));
        wp_schedule_single_event(time() + 10, Stack2_Plugin::CRON_HOOK_SYNC, array(0, 'activation'));
        wp_schedule_single_event(time() + 15, 'unrelated_hook', array());
    }

    public function test_executor_clears_credentials_and_stops_cron(): void
    {
        $this->assertNotFalse(wp_next_scheduled(Stack2_Plugin::CRON_HOOK_SYNC, array(0, 'cron')));
        $this->assertNotFalse(wp_next_scheduled(Stack2_Plugin::CRON_HOOK_SYNC, array(1, 'retry')));

        $executor = $this->executor();
        $result = $executor->execute('disconnect', null, null);

        $this->assertTrue($result['success']);
        $this->assertNull($result['error']);
        $this->assertNull($result['inventory']);
        $this->assertArrayNotHasKey(Stack2_Plugin::OPTION_BASE_URL, $GLOBALS['stack2_options']);
        $this->assertArrayNotHasKey(Stack2_Plugin::OPTION_SITE_ID, $GLOBALS['stack2_options']);
        $this->assertArrayNotHasKey(Stack2_Plugin::OPTION_API_KEY, $GLOBALS['stack2_options']);
        $this->assertSame('success', $GLOBALS['stack2_options'][Stack2_Plugin::OPTION_LAST_SYNC_STATUS]);
        $this->assertArrayNotHasKey('stack2_sync_lock', $GLOBALS['stack2_transients']);
        $this->assertFalse(wp_next_scheduled(Stack2_Plugin::CRON_HOOK_SYNC, array(0, 'cron')));
        $this->assertFalse(wp_next_scheduled(Stack2_Plugin::CRON_HOOK_SYNC, array(1, 'retry')));
        $this->assertFalse(wp_next_scheduled(Stack2_Plugin::CRON_HOOK_SYNC, array(0, 'activation')));
        $this->assertSame(array(), $this->cron_hooks(Stack2_Plugin::CRON_HOOK_SYNC));
        $this->assertNotFalse(wp_next_scheduled('unrelated_hook', array()));
    }

    public function test_clear_scheduled_hook_without_args_does_not_remove_production_cron(): void
    {
        wp_clear_scheduled_hook(Stack2_Plugin::CRON_HOOK_SYNC);

        $this->assertNotFalse(wp_next_scheduled(Stack2_Plugin::CRON_HOOK_SYNC, array(0, 'cron')));
        $this->assertNotFalse(wp_next_scheduled(Stack2_Plugin::CRON_HOOK_SYNC, array(1, 'retry')));
    }

    public function test_signed_disconnect_command_returns_success(): void
    {
        $controller = $this->controller();
        $body = wp_json_encode(array('action' => 'disconnect'));
        $request = new WP_REST_Request();
        $this->sign_command($request, $body);

        $response = $controller->handle_command($request);
        $data = $response->get_data();

        $this->assertSame(200, $response->get_status());
        $this->assertTrue($data['success']);
        $this->assertNull($data['error']);
        $this->assertNull($data['inventory']);
        $this->assertSame('', (string) get_option(Stack2_Plugin::OPTION_API_KEY, ''));
        $this->assertFalse(wp_next_scheduled(Stack2_Plugin::CRON_HOOK_SYNC, array(0, 'cron')));
        $this->assertSame(array(), $this->cron_hooks(Stack2_Plugin::CRON_HOOK_SYNC));
    }

    public function test_disconnect_rejects_bad_hmac(): void
    {
        $controller = $this->controller();
        $body = wp_json_encode(array('action' => 'disconnect'));
        $request = new WP_REST_Request();
        $this->sign_command($request, $body, 'wrong-key');

        $response = $controller->handle_command($request);

        $this->assertSame(401, $response->get_status());
        $this->assertSame(self::API_KEY, get_option(Stack2_Plugin::OPTION_API_KEY));
    }

    public function test_unknown_action_still_rejected(): void
    {
        $controller = $this->controller();
        $body = wp_json_encode(array('action' => 'clear_credentials'));
        $request = new WP_REST_Request();
        $this->sign_command($request, $body);

        $response = $controller->handle_command($request);
        $data = $response->get_data();

        $this->assertSame(400, $response->get_status());
        $this->assertSame('Unsupported action.', $data['error']);
        $this->assertSame(self::API_KEY, get_option(Stack2_Plugin::OPTION_API_KEY));
    }

    private function executor(): Stack2_Command_Executor
    {
        return new Stack2_Command_Executor(
            new Stack2_Inventory_Collector(),
            new Stack2_Logger(),
            self::SITE_ID
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

    /**
     * @return array<int, array>
     */
    private function cron_hooks(string $hook): array
    {
        return array_values(array_filter(
            $GLOBALS['stack2_cron'],
            static function ($event) use ($hook) {
                return ($event['hook'] ?? '') === $hook;
            }
        ));
    }
}
