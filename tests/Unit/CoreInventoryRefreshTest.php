<?php

use PHPUnit\Framework\TestCase;

class CoreInventoryRefreshTest extends TestCase
{
    private const SITE_ID = 'site_test';
    private const API_KEY = 'secret-key';
    private const PLUGIN_FILE = 'akismet/akismet.php';

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
        $GLOBALS['stack2_wp_version'] = '6.8.2';
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
        $GLOBALS['stack2_wp_update_plugins_impl'] = null;
        $GLOBALS['stack2_wp_version_check_impl'] = null;
        $GLOBALS['stack2_get_core_updates_impl'] = null;
        parent::tearDown();
    }

    public function test_refresh_true_returns_core_offers_and_keeps_plugin_fields(): void
    {
        $this->install_akismet('5.0');
        set_site_transient('update_core', (object) array(
            'updates' => array(),
            'last_checked' => time(),
        ));
        $order = array();
        $GLOBALS['stack2_wp_update_plugins_impl'] = static function () use (&$order) {
            $order[] = 'wp_update_plugins';
            set_site_transient('update_plugins', (object) array(
                'response' => array(
                    self::PLUGIN_FILE => (object) array(
                        'new_version' => '5.3',
                        'package' => 'https://downloads.wordpress.org/plugin/akismet.5.3.zip',
                        'upgrade_notice' => 'Security fix.',
                    ),
                ),
            ));
        };
        $GLOBALS['stack2_wp_version_check_impl'] = static function () use (&$order) {
            $order[] = 'wp_version_check';
        };
        $GLOBALS['stack2_get_core_updates_impl'] = static function () use (&$order) {
            $order[] = 'get_core_updates';

            return array(
                (object) array(
                    'response' => 'upgrade',
                    'version' => '6.8.3',
                    'php_version' => '7.2.24',
                    'locale' => 'en_US',
                    'download' => 'https://downloads.wordpress.org/release/wordpress-6.8.3.zip',
                    'packages' => (object) array(
                        'full' => 'https://downloads.wordpress.org/release/wordpress-6.8.3.zip',
                        'partial' => '',
                    ),
                    'dismissed' => false,
                ),
            );
        };

        $response = $this->signed_command(array(
            'action' => 'inventory',
            'plugin' => null,
            'slug' => null,
            'refresh' => true,
        ));
        $inventory = $response['body']['inventory'];
        $plugin = $inventory['plugins'][0];

        $this->assertSame(200, $response['status_code']);
        $this->assertTrue($response['body']['success']);
        $this->assertNull($response['body']['error']);
        $this->assertSame(array('wp_update_plugins', 'wp_version_check', 'get_core_updates'), $order);
        $this->assertIsObject(get_site_transient('update_core'));
        $this->assertSame(array(array()), $GLOBALS['stack2_wp_version_check_args']);
        $this->assertSame(array(true), $GLOBALS['stack2_wp_version_check_force']);
        $this->assertSame(array(array()), $GLOBALS['stack2_get_core_updates_args']);
        $this->assertSame(1, $GLOBALS['stack2_wp_update_plugins_calls']);
        $this->assertSame(self::SITE_ID, $inventory['site_id']);
        $this->assertSame('6.8.2', $inventory['wp_version']);
        $this->assertSame(PHP_VERSION, $inventory['php_version']);
        $this->assertSame('akismet', $plugin['slug']);
        $this->assertSame(self::PLUGIN_FILE, $plugin['file']);
        $this->assertSame('Akismet', $plugin['name']);
        $this->assertSame('5.0', $plugin['version']);
        $this->assertTrue($plugin['has_update']);
        $this->assertSame('5.3', $plugin['latest_version']);
        $this->assertTrue($plugin['update_package_available']);
        $this->assertSame('Security fix.', $plugin['upgrade_notice']);
        $this->assertArrayNotHasKey('core_update', $plugin);
        $this->assertSame(
            array(
                'checked' => true,
                'updates' => array(
                    array(
                        'response' => 'upgrade',
                        'version' => '6.8.3',
                        'php_version' => '7.2.24',
                        'locale' => 'en_US',
                        'download' => 'https://downloads.wordpress.org/release/wordpress-6.8.3.zip',
                        'packages' => array(
                            'full' => 'https://downloads.wordpress.org/release/wordpress-6.8.3.zip',
                            'partial' => '',
                        ),
                        'dismissed' => false,
                    ),
                ),
            ),
            $inventory['core_update']
        );
    }

    public function test_refresh_false_omits_core_update(): void
    {
        $this->install_akismet('5.0');

        $response = $this->signed_command(array(
            'action' => 'inventory',
            'plugin' => null,
            'slug' => null,
            'refresh' => false,
        ));

        $this->assertSame(200, $response['status_code']);
        $this->assertTrue($response['body']['success']);
        $this->assertArrayNotHasKey('core_update', $response['body']['inventory']);
        $this->assertSame('5.0', $response['body']['inventory']['plugins'][0]['version']);
        $this->assertNull($response['body']['inventory']['plugins'][0]['latest_version']);
        $this->assertSame(0, $GLOBALS['stack2_wp_update_plugins_calls']);
        $this->assertSame(0, $GLOBALS['stack2_wp_version_check_calls']);
        $this->assertSame(0, $GLOBALS['stack2_get_core_updates_calls']);
    }

    public function test_omitted_refresh_and_sync_collect_omit_core_update(): void
    {
        $this->install_akismet('5.0');

        $signed = $this->signed_command(array(
            'action' => 'inventory',
            'plugin' => null,
            'slug' => null,
        ));
        $synced = (new Stack2_Inventory_Collector())->collect(self::SITE_ID);

        $this->assertArrayNotHasKey('core_update', $signed['body']['inventory']);
        $this->assertArrayNotHasKey('core_update', $synced);
        $this->assertSame(0, $GLOBALS['stack2_wp_version_check_calls']);
        $this->assertSame(0, $GLOBALS['stack2_get_core_updates_calls']);
        $this->assertSame(0, $GLOBALS['stack2_wp_update_plugins_calls']);
    }

    public function test_empty_core_updates_are_an_empty_list(): void
    {
        $GLOBALS['stack2_wp_version_check_impl'] = static function () {
        };
        $GLOBALS['stack2_get_core_updates_impl'] = static function () {
            return false;
        };

        $none = (new Stack2_Inventory_Collector())->collect(self::SITE_ID, true);

        $this->assertTrue($none['core_update']['checked']);
        $this->assertSame(array(), $none['core_update']['updates']);

        $GLOBALS['stack2_get_core_updates_impl'] = static function () {
            return array();
        };
        $empty = (new Stack2_Inventory_Collector())->collect(self::SITE_ID, true);

        $this->assertSame(array(), $empty['core_update']['updates']);
    }

    public function test_latest_response_is_passed_through(): void
    {
        $GLOBALS['stack2_wp_version_check_impl'] = static function () {
        };
        $GLOBALS['stack2_get_core_updates_impl'] = static function () {
            return array(
                'skip-me',
                (object) array(
                    'response' => 'latest',
                    'version' => '6.8.2',
                    'php_version' => '7.2.24',
                ),
            );
        };

        $inventory = (new Stack2_Inventory_Collector())->collect(self::SITE_ID, true);

        $this->assertSame(
            array(
                array(
                    'response' => 'latest',
                    'version' => '6.8.2',
                    'php_version' => '7.2.24',
                ),
            ),
            $inventory['core_update']['updates']
        );
    }

    public function test_core_check_failure_still_returns_plugin_inventory(): void
    {
        $this->install_akismet('5.0');
        $GLOBALS['stack2_wp_update_plugins_impl'] = static function () {
            set_site_transient('update_plugins', (object) array(
                'response' => array(
                    self::PLUGIN_FILE => (object) array(
                        'new_version' => '5.3',
                        'package' => 'https://downloads.wordpress.org/plugin/akismet.5.3.zip',
                    ),
                ),
            ));
        };
        $GLOBALS['stack2_wp_version_check_impl'] = static function () {
            throw new RuntimeException('api.wordpress.org down');
        };

        $result = $this->executor()->execute('inventory', null, null, array('refresh' => true));
        $plugin = $result['inventory']['plugins'][0];

        $this->assertTrue($result['success']);
        $this->assertNull($result['error']);
        $this->assertArrayNotHasKey('core_update', $result['inventory']);
        $this->assertSame('akismet', $plugin['slug']);
        $this->assertSame('5.0', $plugin['version']);
        $this->assertTrue($plugin['has_update']);
        $this->assertSame('5.3', $plugin['latest_version']);
        $this->assertTrue($plugin['update_package_available']);
        $this->assertSame(1, $GLOBALS['stack2_wp_update_plugins_calls']);
        $this->assertSame(1, $GLOBALS['stack2_wp_version_check_calls']);
        $this->assertSame(0, $GLOBALS['stack2_get_core_updates_calls']);
    }

    private function install_akismet(string $version): void
    {
        $GLOBALS['stack2_plugins'] = array(
            self::PLUGIN_FILE => array(
                'Name' => 'Akismet',
                'Version' => $version,
                'Author' => 'Automattic',
                'PluginURI' => 'https://akismet.com/',
                'Description' => 'Spam protection',
            ),
        );
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
