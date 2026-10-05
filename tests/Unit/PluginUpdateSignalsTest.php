<?php

use PHPUnit\Framework\TestCase;

class PluginUpdateSignalsTest extends TestCase
{
    private const SITE_ID = 'site_test';
    private const API_KEY = 'secret-key';
    private const ELEMENTOR = 'elementor-pro/elementor-pro.php';
    private const LICENSE_MESSAGE = 'Your Elementor Pro license has expired. Please renew to update.';

    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['stack2_options'] = array(
            Stack2_Plugin::OPTION_BASE_URL => 'https://app.stack2.au',
            Stack2_Plugin::OPTION_SITE_ID => self::SITE_ID,
            Stack2_Plugin::OPTION_API_KEY => self::API_KEY,
        );
        $GLOBALS['stack2_transients'] = array();
        $GLOBALS['stack2_plugins'] = array();
        $GLOBALS['stack2_active_plugins'] = array();
        $GLOBALS['stack2_upgrader_skin_messages'] = array();
        $GLOBALS['stack2_plugin_upgrader_bulk'] = null;
        $GLOBALS['stack2_wp_update_plugins_calls'] = 0;
        $GLOBALS['stack2_wp_update_plugins_impl'] = null;
        $GLOBALS['stack2_wp_version_check_calls'] = 0;
        $GLOBALS['stack2_wp_version_check_args'] = array();
        $GLOBALS['stack2_wp_version_check_force'] = array();
        $GLOBALS['stack2_wp_version_check_impl'] = null;
        $GLOBALS['stack2_get_core_updates_calls'] = 0;
        $GLOBALS['stack2_get_core_updates_args'] = array();
        $GLOBALS['stack2_get_core_updates_impl'] = null;
    }

    protected function tearDown(): void
    {
        $GLOBALS['stack2_plugin_upgrader_bulk'] = null;
        $GLOBALS['stack2_wp_update_plugins_impl'] = null;
        $GLOBALS['stack2_wp_version_check_impl'] = null;
        $GLOBALS['stack2_get_core_updates_impl'] = null;
        $GLOBALS['stack2_upgrader_skin_messages'] = array();
        parent::tearDown();
    }

    public function test_update_passes_through_vendor_license_error(): void
    {
        $this->install_elementor('3.24.0');
        $GLOBALS['stack2_upgrader_skin_messages'] = array(
            'Downloading update from https://my.elementor.com/download/',
            '<strong>' . self::LICENSE_MESSAGE . '</strong>',
        );
        $GLOBALS['stack2_plugin_upgrader_bulk'] = static function (array $plugins) {
            return array(
                $plugins[0] => new WP_Error('download_failed', self::LICENSE_MESSAGE),
            );
        };

        $result = $this->executor()->execute('update', null, 'elementor-pro');

        $this->assertFalse($result['success']);
        $this->assertSame(self::LICENSE_MESSAGE, $result['error']);
        $this->assertSame('download_failed', $result['error_code']);
        $this->assertFalse($result['not_applied']);
        $this->assertSame('3.24.0', $result['plugin_version']);
        $this->assertSame(
            array(
                'Downloading update from https://my.elementor.com/download/',
                self::LICENSE_MESSAGE,
            ),
            $result['skin_messages']
        );
        $this->assertStringNotContainsString('Filesystem credentials', $result['error']);
        $this->assertNull($result['inventory']);
    }

    public function test_signed_update_returns_vendor_reason_to_the_client(): void
    {
        $this->install_elementor('3.24.0');
        $GLOBALS['stack2_upgrader_skin_messages'] = array(self::LICENSE_MESSAGE);
        $GLOBALS['stack2_plugin_upgrader_bulk'] = static function (array $plugins) {
            return array(
                $plugins[0] => new WP_Error('download_failed', self::LICENSE_MESSAGE),
            );
        };

        $data = $this->signed_command(array(
            'action' => 'update',
            'plugin' => self::ELEMENTOR,
        ));

        $this->assertSame(400, $data['status_code']);
        $this->assertFalse($data['body']['success']);
        $this->assertSame(self::LICENSE_MESSAGE, $data['body']['error']);
        $this->assertSame('download_failed', $data['body']['error_code']);
        $this->assertSame(array(self::LICENSE_MESSAGE), $data['body']['skin_messages']);
        $this->assertFalse($data['body']['not_applied']);
        $this->assertArrayNotHasKey('installed_version', $data['body']);
    }

    public function test_update_reports_not_applied_when_version_is_unchanged(): void
    {
        $this->install_elementor('3.24.0');
        $GLOBALS['stack2_upgrader_skin_messages'] = array('Plugin updated successfully.');
        $GLOBALS['stack2_plugin_upgrader_bulk'] = static function (array $plugins) {
            return array($plugins[0] => array('destination' => WP_PLUGIN_DIR));
        };

        $result = $this->executor()->execute('update', self::ELEMENTOR, null);

        $this->assertFalse($result['success']);
        $this->assertTrue($result['not_applied']);
        $this->assertSame('not_applied', $result['error_code']);
        $this->assertSame('Plugin update did not change the installed version (3.24.0).', $result['error']);
        $this->assertSame('3.24.0', $result['plugin_version']);
        $this->assertSame(array('Plugin updated successfully.'), $result['skin_messages']);
        $this->assertNull($result['inventory']);
    }

    public function test_update_success_reports_new_version_and_reactivates(): void
    {
        $this->install_elementor('3.24.0');
        $GLOBALS['stack2_active_plugins'] = array(self::ELEMENTOR);
        $GLOBALS['stack2_plugin_upgrader_bulk'] = static function (array $plugins) {
            $GLOBALS['stack2_active_plugins'] = array();
            $GLOBALS['stack2_plugins'][self::ELEMENTOR]['Version'] = '3.25.0';

            return array($plugins[0] => array('destination' => WP_PLUGIN_DIR));
        };

        $result = $this->executor()->execute('update', self::ELEMENTOR, null);

        $this->assertTrue($result['success']);
        $this->assertNull($result['error']);
        $this->assertFalse($result['not_applied']);
        $this->assertSame('3.25.0', $result['plugin_version']);
        $this->assertArrayNotHasKey('error_code', $result);
        $this->assertArrayNotHasKey('skin_messages', $result);
        $this->assertContains(self::ELEMENTOR, $GLOBALS['stack2_active_plugins']);
        $this->assertSame('3.25.0', $result['inventory']['plugins'][0]['version']);
        $this->assertArrayNotHasKey('core_update', $result['inventory']);
        $this->assertSame(0, $GLOBALS['stack2_wp_version_check_calls']);
    }

    public function test_update_without_a_wordpress_reason_keeps_filesystem_fallback(): void
    {
        $this->install_elementor('3.24.0');
        $GLOBALS['stack2_plugin_upgrader_bulk'] = static function () {
            return false;
        };

        $result = $this->executor()->execute('update', self::ELEMENTOR, null);

        $this->assertFalse($result['success']);
        $this->assertSame('Plugin update failed. Filesystem credentials may be required.', $result['error']);
        $this->assertSame('fs_credentials', $result['error_code']);
        $this->assertFalse($result['not_applied']);
        $this->assertSame(array(), $result['skin_messages']);
    }

    public function test_update_uses_skin_message_when_upgrader_returns_no_error_object(): void
    {
        $this->install_elementor('3.24.0');
        $GLOBALS['stack2_upgrader_skin_messages'] = array('Could not copy file: elementor-pro/elementor-pro.php');
        $GLOBALS['stack2_plugin_upgrader_bulk'] = static function () {
            return false;
        };

        $result = $this->executor()->execute('update', self::ELEMENTOR, null);

        $this->assertSame('Could not copy file: elementor-pro/elementor-pro.php', $result['error']);
        $this->assertSame('update_failed', $result['error_code']);
        $this->assertFalse($result['not_applied']);
    }

    public function test_inventory_reports_package_availability_and_upgrade_notice(): void
    {
        $GLOBALS['stack2_plugins'] = array(
            'akismet/akismet.php' => $this->plugin_header('Akismet', '5.0'),
            self::ELEMENTOR => $this->plugin_header('Elementor Pro', '3.24.0'),
            'hello.php' => $this->plugin_header('Hello Dolly', '1.7.2'),
        );
        set_site_transient('update_plugins', (object) array(
            'response' => array(
                'akismet/akismet.php' => (object) array(
                    'new_version' => '5.3',
                    'package' => 'https://downloads.wordpress.org/plugin/akismet.5.3.zip',
                    'upgrade_notice' => '<p>Security fix.</p>',
                ),
                self::ELEMENTOR => (object) array(
                    'new_version' => '3.25.0',
                    'package' => '',
                ),
                'hello.php' => array(
                    'new_version' => '1.7.3',
                ),
            ),
        ));

        $plugins = $this->plugins_by_file((new Stack2_Inventory_Collector())->collect(self::SITE_ID));

        $this->assertTrue($plugins['akismet/akismet.php']['has_update']);
        $this->assertSame('5.3', $plugins['akismet/akismet.php']['latest_version']);
        $this->assertTrue($plugins['akismet/akismet.php']['update_package_available']);
        $this->assertSame('Security fix.', $plugins['akismet/akismet.php']['upgrade_notice']);

        $this->assertTrue($plugins[self::ELEMENTOR]['has_update']);
        $this->assertFalse($plugins[self::ELEMENTOR]['update_package_available']);
        $this->assertNull($plugins[self::ELEMENTOR]['upgrade_notice']);

        $this->assertTrue($plugins['hello.php']['has_update']);
        $this->assertSame('1.7.3', $plugins['hello.php']['latest_version']);
        $this->assertNull($plugins['hello.php']['update_package_available']);
        $this->assertNull($plugins['hello.php']['upgrade_notice']);
        $this->assertSame(0, $GLOBALS['stack2_wp_update_plugins_calls']);
    }

    public function test_inventory_refresh_reruns_update_check_before_collect(): void
    {
        $GLOBALS['stack2_plugins'] = array(
            'akismet/akismet.php' => $this->plugin_header('Akismet', '5.0'),
        );
        set_site_transient('update_plugins', (object) array(
            'response' => array(
                'akismet/akismet.php' => (object) array(
                    'new_version' => '5.0.1',
                    'package' => '',
                ),
            ),
        ));
        $seen_during_check = 'not-called';
        $GLOBALS['stack2_wp_update_plugins_impl'] = static function () use (&$seen_during_check) {
            $seen_during_check = get_site_transient('update_plugins');
            set_site_transient('update_plugins', (object) array(
                'response' => array(
                    'akismet/akismet.php' => (object) array(
                        'new_version' => '5.3',
                        'package' => 'https://downloads.wordpress.org/plugin/akismet.5.3.zip',
                        'upgrade_notice' => 'Security fix.',
                    ),
                ),
            ));
        };

        $inventory = (new Stack2_Inventory_Collector())->collect(self::SITE_ID, true);
        $plugin = $inventory['plugins'][0];

        $this->assertFalse($seen_during_check);
        $this->assertSame(1, $GLOBALS['stack2_wp_update_plugins_calls']);
        $this->assertSame('5.3', $plugin['latest_version']);
        $this->assertTrue($plugin['update_package_available']);
        $this->assertSame('Security fix.', $plugin['upgrade_notice']);
    }

    public function test_signed_inventory_refresh_flag(): void
    {
        $GLOBALS['stack2_plugins'] = array(
            'hello.php' => $this->plugin_header('Hello Dolly', '1.7.2'),
        );

        $skipped = $this->signed_command(array('action' => 'inventory', 'refresh' => 'false'));
        $this->assertSame(200, $skipped['status_code']);
        $this->assertSame(0, $GLOBALS['stack2_wp_update_plugins_calls']);
        $this->assertSame(0, $GLOBALS['stack2_wp_version_check_calls']);
        $this->assertArrayNotHasKey('core_update', $skipped['body']['inventory']);
        $this->assertNull($skipped['body']['inventory']['plugins'][0]['update_package_available']);
        $this->assertNull($skipped['body']['inventory']['plugins'][0]['upgrade_notice']);

        $refreshed = $this->signed_command(array('action' => 'inventory', 'refresh' => true));
        $this->assertSame(200, $refreshed['status_code']);
        $this->assertTrue($refreshed['body']['success']);
        $this->assertSame(1, $GLOBALS['stack2_wp_update_plugins_calls']);
        $this->assertSame(1, $GLOBALS['stack2_wp_version_check_calls']);
        $this->assertTrue($refreshed['body']['inventory']['core_update']['checked']);
    }

    private function install_elementor(string $version): void
    {
        $GLOBALS['stack2_plugins'] = array(
            self::ELEMENTOR => $this->plugin_header('Elementor Pro', $version),
        );
    }

    /**
     * @return array<string, string>
     */
    private function plugin_header(string $name, string $version): array
    {
        return array(
            'Name' => $name,
            'Version' => $version,
            'Author' => 'Test',
            'PluginURI' => 'https://example.com',
            'Description' => 'Test plugin',
        );
    }

    /**
     * @param array<string, mixed> $inventory
     * @return array<string, array<string, mixed>>
     */
    private function plugins_by_file(array $inventory): array
    {
        $indexed = array();
        foreach ($inventory['plugins'] as $plugin) {
            $indexed[$plugin['file']] = $plugin;
        }

        return $indexed;
    }

    private function executor(): Stack2_Command_Executor
    {
        return new Stack2_Command_Executor(
            new Stack2_Inventory_Collector(),
            new Stack2_Logger(),
            self::SITE_ID
        );
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{status_code: int, body: array<string, mixed>}
     */
    private function signed_command(array $payload): array
    {
        $controller = new Stack2_REST_Controller(
            new Stack2_Signature_Service(),
            $this->executor(),
            new Stack2_Logger(),
            self::SITE_ID,
            self::API_KEY
        );
        $body = wp_json_encode($payload);
        $request = new WP_REST_Request();
        $service = new Stack2_Signature_Service();
        $timestamp = (string) time();
        $request->set_header('x-stack2-site-id', self::SITE_ID);
        $request->set_header('x-stack2-timestamp', $timestamp);
        $request->set_header(
            'x-stack2-signature',
            $service->sign($service->build_command_message($timestamp, $service->sha256_hex($body)), self::API_KEY)
        );
        $request->set_body($body);

        $response = $controller->handle_command($request);

        return array(
            'status_code' => $response->get_status(),
            'body' => $response->get_data(),
        );
    }
}
