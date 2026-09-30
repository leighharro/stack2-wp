<?php

use PHPUnit\Framework\TestCase;

/**
 * WordPress 6.5+ dependency fields and optional WC/Elementor headers.
 *
 * WP_Plugin_Dependencies below is a stand-in for core: it reads Requires Plugins
 * from get_plugins() after initialize(), then asks plugins_api() the way core
 * does on plugins.php. Real-site steps are in stack2-connector/README.md
 * ("Verify on a WooCommerce site").
 */
class PluginDependencyInventoryTest extends TestCase
{
    private const SITE_ID = 'site_test';
    private const API_KEY = 'secret-key';

    private const STRIPE = 'woocommerce-gateway-stripe/woocommerce-gateway-stripe.php';
    private const WOO = 'woocommerce/woocommerce.php';
    private const CYCLE_A = 'cycle-a/cycle-a.php';
    private const CYCLE_B = 'cycle-b/cycle-b.php';
    private const SELF_LOOP = 'self-loop/self-loop.php';
    private const ELEMENTOR = 'elementor/elementor.php';
    private const ELEMENTOR_PRO = 'elementor-pro/elementor-pro.php';
    private const BUNDLED = 'bundled/bundled.php';

    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['stack2_plugins'] = array();
        $GLOBALS['stack2_active_plugins'] = array();
        $GLOBALS['stack2_transients'] = array();
        $GLOBALS['stack2_filters'] = array();
        $GLOBALS['stack2_outbound_http_attempts'] = 0;
        $GLOBALS['stack2_outbound_http'] = array();
        $GLOBALS['stack2_plugins_api_calls'] = 0;
        $GLOBALS['stack2_file_data_calls'] = array();
        $GLOBALS['stack2_wp_version'] = '6.8';
        $GLOBALS['stack2_options'] = array(
            Stack2_Plugin::OPTION_BASE_URL => 'https://app.stack2.au',
            Stack2_Plugin::OPTION_SITE_ID => self::SITE_ID,
            Stack2_Plugin::OPTION_API_KEY => self::API_KEY,
        );
        WP_Plugin_Dependencies::reset();
        $this->remove_fixture_files();
    }

    protected function tearDown(): void
    {
        $this->remove_fixture_files();
        $GLOBALS['stack2_filters'] = array();
        unset($GLOBALS['stack2_wp_version']);
        WP_Plugin_Dependencies::reset();
        parent::tearDown();
    }

    public function test_wordpress_65_reports_dependencies_without_outbound_http(): void
    {
        $this->install_fixtures();
        $GLOBALS['stack2_wp_version'] = '6.5.3';

        $this->assertUnfilteredPluginsApiReachesWordpressOrg();

        $api_calls_before = (int) $GLOBALS['stack2_plugins_api_calls'];
        $inventory = (new Stack2_Inventory_Collector())->collect(self::SITE_ID);
        $plugins = $this->plugins_by_file($inventory);

        $this->assertGreaterThan($api_calls_before, (int) $GLOBALS['stack2_plugins_api_calls']);
        $this->assertSame(0, (int) $GLOBALS['stack2_outbound_http_attempts']);
        $this->assertSame(array(), $GLOBALS['stack2_outbound_http']);
        $this->assertSame(1, $this->log_count('initialize'));
        $this->assertLessThan(
            $this->first_log_index('get_dependencies:'),
            $this->first_log_index('initialize')
        );
        $this->assertFiltersRemoved();

        $this->assertSame(array(), $plugins[self::WOO]['requires_plugins']);
        $this->assertFalse($plugins[self::WOO]['has_circular_dependency']);
        $this->assertArrayNotHasKey('wc_requires_at_least', $plugins[self::WOO]);
        $this->assertArrayNotHasKey('wc_tested_up_to', $plugins[self::WOO]);

        $this->assertSame(array('woocommerce'), $plugins[self::STRIPE]['requires_plugins']);
        $this->assertFalse($plugins[self::STRIPE]['has_circular_dependency']);
        $this->assertSame('8.6', $plugins[self::STRIPE]['wc_requires_at_least']);
        $this->assertSame('9.4', $plugins[self::STRIPE]['wc_tested_up_to']);
        $this->assertArrayNotHasKey('elementor_tested_up_to', $plugins[self::STRIPE]);
        $this->assertArrayNotHasKey('elementor_pro_tested_up_to', $plugins[self::STRIPE]);

        $this->assertSame(array('jetpack', 'woocommerce'), $plugins[self::BUNDLED]['requires_plugins']);
        $this->assertFalse($plugins[self::BUNDLED]['has_circular_dependency']);

        $this->assertTrue($plugins[self::CYCLE_A]['has_circular_dependency']);
        $this->assertTrue($plugins[self::CYCLE_B]['has_circular_dependency']);
        $this->assertSame(array('cycle-b'), $plugins[self::CYCLE_A]['requires_plugins']);
        $this->assertSame(array('cycle-a'), $plugins[self::CYCLE_B]['requires_plugins']);
        $this->assertTrue($plugins[self::SELF_LOOP]['has_circular_dependency']);
        $this->assertSame(array('self-loop'), $plugins[self::SELF_LOOP]['requires_plugins']);

        $this->assertSame('3.24.0', $plugins[self::ELEMENTOR]['elementor_tested_up_to']);
        $this->assertArrayNotHasKey('elementor_pro_tested_up_to', $plugins[self::ELEMENTOR]);
        $this->assertArrayNotHasKey('wc_requires_at_least', $plugins[self::ELEMENTOR]);
        $this->assertSame(array(), $plugins[self::ELEMENTOR]['requires_plugins']);

        $this->assertSame('3.24.0', $plugins[self::ELEMENTOR_PRO]['elementor_pro_tested_up_to']);
        $this->assertSame('3.24.0', $plugins[self::ELEMENTOR_PRO]['elementor_tested_up_to']);
        $this->assertArrayNotHasKey('wc_tested_up_to', $plugins[self::ELEMENTOR_PRO]);

        $plain = $plugins['plain.php'];
        $this->assertSame(array(), $plain['requires_plugins']);
        $this->assertFalse($plain['has_circular_dependency']);
        $this->assertArrayNotHasKey('wc_requires_at_least', $plain);
        $this->assertArrayNotHasKey('wc_tested_up_to', $plain);
        $this->assertArrayNotHasKey('elementor_tested_up_to', $plain);
        $this->assertArrayNotHasKey('elementor_pro_tested_up_to', $plain);

        foreach ($plugins as $plugin) {
            $this->assertArrayNotHasKey('dependency_warnings', $plugin);
            $this->assertArrayNotHasKey('wc_incompatible', $plugin);
        }

        $this->assertCompatibilityHeadersWereReadFromPluginFiles();

        plugins_api('plugin_information', array('slug' => 'woocommerce'));
        $this->assertSame(1, (int) $GLOBALS['stack2_outbound_http_attempts']);
    }

    public function test_release_candidate_65_includes_dependency_fields(): void
    {
        $this->install_fixtures();
        $GLOBALS['stack2_wp_version'] = '6.5-RC1';

        $plugins = $this->plugins_by_file((new Stack2_Inventory_Collector())->collect(self::SITE_ID));

        $this->assertSame(array('woocommerce'), $plugins[self::STRIPE]['requires_plugins']);
        $this->assertFalse($plugins[self::STRIPE]['has_circular_dependency']);
        $this->assertSame(0, (int) $GLOBALS['stack2_outbound_http_attempts']);
    }

    public function test_wordpress_below_65_omits_dependency_fields(): void
    {
        $this->install_fixtures();
        $GLOBALS['stack2_wp_version'] = '6.4.5';
        $api_calls_before = (int) $GLOBALS['stack2_plugins_api_calls'];

        $plugins = $this->plugins_by_file((new Stack2_Inventory_Collector())->collect(self::SITE_ID));
        $stripe = $plugins[self::STRIPE];

        $this->assertArrayNotHasKey('requires_plugins', $stripe);
        $this->assertArrayNotHasKey('has_circular_dependency', $stripe);
        $this->assertSame('8.6', $stripe['wc_requires_at_least']);
        $this->assertSame('9.4', $stripe['wc_tested_up_to']);
        $this->assertSame('3.24.0', $plugins[self::ELEMENTOR_PRO]['elementor_pro_tested_up_to']);
        $this->assertSame(0, $this->log_count('initialize'));
        $this->assertSame($api_calls_before, (int) $GLOBALS['stack2_plugins_api_calls']);
        $this->assertSame(0, (int) $GLOBALS['stack2_outbound_http_attempts']);
        $this->assertSame('6.4.5', (new Stack2_Inventory_Collector())->collect(self::SITE_ID)['wp_version']);
    }

    public function test_blank_compatibility_header_is_omitted(): void
    {
        $GLOBALS['stack2_wp_version'] = '6.8';
        $GLOBALS['stack2_plugins'] = array(
            'blank/blank.php' => $this->plugin_header('Blank', '1.0', ''),
        );
        $this->write_plugin(
            'blank/blank.php',
            "<?php\n/**\n * Plugin Name: Blank\n * WC requires at least: 8.2\n * WC tested up to:\n */\n"
        );

        $plugin = $this->plugins_by_file((new Stack2_Inventory_Collector())->collect(self::SITE_ID))['blank/blank.php'];

        $this->assertSame('8.2', $plugin['wc_requires_at_least']);
        $this->assertArrayNotHasKey('wc_tested_up_to', $plugin);
        $this->assertSame(array(), $plugin['requires_plugins']);
        $this->assertFalse($plugin['has_circular_dependency']);
    }

    public function test_plugin_path_outside_the_plugins_directory_skips_header_reads(): void
    {
        $GLOBALS['stack2_plugins'] = array(
            '../wp-config.php' => $this->plugin_header('Escape', '1.0', ''),
        );
        $GLOBALS['stack2_file_data_calls'] = array();

        $plugin = $this->plugins_by_file((new Stack2_Inventory_Collector())->collect(self::SITE_ID))['../wp-config.php'];

        $this->assertSame(array(), $GLOBALS['stack2_file_data_calls']);
        $this->assertArrayNotHasKey('wc_requires_at_least', $plugin);
        $this->assertSame(array(), $plugin['requires_plugins']);
    }

    public function test_signed_inventory_command_returns_dependency_fields(): void
    {
        $this->install_fixtures();
        $GLOBALS['stack2_wp_version'] = '6.7.1';

        $response = $this->signed_command(array('action' => 'inventory'));
        $plugins = $this->plugins_by_file($response['body']['inventory']);

        $this->assertSame(200, $response['status_code']);
        $this->assertTrue($response['body']['success']);
        $this->assertSame(array('woocommerce'), $plugins[self::STRIPE]['requires_plugins']);
        $this->assertFalse($plugins[self::STRIPE]['has_circular_dependency']);
        $this->assertSame('8.6', $plugins[self::STRIPE]['wc_requires_at_least']);
        $this->assertSame(0, (int) $GLOBALS['stack2_outbound_http_attempts']);
    }

    private function assertUnfilteredPluginsApiReachesWordpressOrg(): void
    {
        $GLOBALS['stack2_outbound_http_attempts'] = 0;
        $GLOBALS['stack2_outbound_http'] = array();

        $result = plugins_api('plugin_information', array('slug' => 'woocommerce'));

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame(1, (int) $GLOBALS['stack2_outbound_http_attempts']);
        $this->assertSame('POST', $GLOBALS['stack2_outbound_http'][0]['method']);
        $this->assertSame('https://api.wordpress.org/plugins/info/1.2/', $GLOBALS['stack2_outbound_http'][0]['url']);

        $GLOBALS['stack2_outbound_http_attempts'] = 0;
        $GLOBALS['stack2_outbound_http'] = array();
    }

    private function assertFiltersRemoved(): void
    {
        $plugins_api = $GLOBALS['stack2_filters']['plugins_api'][PHP_INT_MAX] ?? array();
        $http = $GLOBALS['stack2_filters']['pre_http_request'][PHP_INT_MAX] ?? array();
        $this->assertSame(array(), array_values($plugins_api));
        $this->assertSame(array(), array_values($http));
    }

    private function assertCompatibilityHeadersWereReadFromPluginFiles(): void
    {
        $expected = array(
            'wc_requires_at_least' => 'WC requires at least',
            'wc_tested_up_to' => 'WC tested up to',
            'elementor_tested_up_to' => 'Elementor tested up to',
            'elementor_pro_tested_up_to' => 'Elementor Pro tested up to',
        );
        $stripe_calls = array_values(array_filter(
            $GLOBALS['stack2_file_data_calls'],
            static function ($call) {
                return is_string($call['file'] ?? null)
                    && str_ends_with($call['file'], self::STRIPE);
            }
        ));

        $this->assertCount(1, $stripe_calls);
        $this->assertSame($expected, $stripe_calls[0]['headers']);
        $this->assertSame('plugin', $stripe_calls[0]['context']);
    }

    private function install_fixtures(): void
    {
        $GLOBALS['stack2_plugins'] = array(
            self::WOO => $this->plugin_header('WooCommerce', '9.3.0', ''),
            self::STRIPE => $this->plugin_header('WooCommerce Stripe Gateway', '8.7.0', 'woocommerce'),
            self::BUNDLED => $this->plugin_header('Bundled', '1.0.0', 'woocommerce, jetpack'),
            self::CYCLE_A => $this->plugin_header('Cycle A', '1.0.0', 'cycle-b'),
            self::CYCLE_B => $this->plugin_header('Cycle B', '1.0.0', 'cycle-a'),
            self::SELF_LOOP => $this->plugin_header('Self Loop', '1.0.0', 'self-loop'),
            self::ELEMENTOR => $this->plugin_header('Elementor', '3.24.0', ''),
            self::ELEMENTOR_PRO => $this->plugin_header('Elementor Pro', '3.24.0', 'elementor'),
            'plain.php' => $this->plugin_header('Plain', '1.0.0', ''),
        );

        $this->write_plugin(self::WOO, "<?php\n/**\n * Plugin Name: WooCommerce\n * Requires Plugins:\n */\n");
        $this->write_plugin(
            self::STRIPE,
            "<?php\n/**\n * Plugin Name: WooCommerce Stripe Gateway\n * Requires Plugins: woocommerce\n * WC requires at least: 8.6\n * WC tested up to: 9.4\n */\n"
        );
        $this->write_plugin(
            self::ELEMENTOR,
            "<?php\n/**\n * Plugin Name: Elementor\n * Elementor tested up to: 3.24.0\n */\n"
        );
        $this->write_plugin(
            self::ELEMENTOR_PRO,
            "<?php\n/**\n * Plugin Name: Elementor Pro\n * Requires Plugins: elementor\n * Elementor tested up to: 3.24.0\n * Elementor Pro tested up to: 3.24.0\n * WC tested up to:   \n */\n"
        );
    }

    /**
     * @return array<string, string>
     */
    private function plugin_header(string $name, string $version, string $requires_plugins): array
    {
        return array(
            'Name' => $name,
            'Version' => $version,
            'Author' => 'Test',
            'PluginURI' => 'https://example.com',
            'Description' => 'Test plugin',
            'RequiresPlugins' => $requires_plugins,
        );
    }

    private function write_plugin(string $relative, string $contents): void
    {
        $path = WP_PLUGIN_DIR . '/' . $relative;
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
            $this->fail('Could not create plugin fixture directory.');
        }
        file_put_contents($path, $contents);
    }

    private function remove_fixture_files(): void
    {
        $files = array(
            self::WOO,
            self::STRIPE,
            self::ELEMENTOR,
            self::ELEMENTOR_PRO,
            'blank/blank.php',
        );
        foreach ($files as $relative) {
            $path = WP_PLUGIN_DIR . '/' . $relative;
            if (is_file($path)) {
                unlink($path);
            }
        }
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

    private function log_count(string $entry): int
    {
        return count(array_filter(
            WP_Plugin_Dependencies::$log,
            static function ($logged) use ($entry) {
                return $logged === $entry;
            }
        ));
    }

    private function first_log_index(string $prefix): int
    {
        foreach (WP_Plugin_Dependencies::$log as $index => $entry) {
            if ($entry === $prefix || str_starts_with($entry, $prefix)) {
                return (int) $index;
            }
        }

        $this->fail('Missing dependency log entry: ' . $prefix);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{status_code: int, body: array<string, mixed>}
     */
    private function signed_command(array $payload): array
    {
        $executor = new Stack2_Command_Executor(
            new Stack2_Inventory_Collector(),
            new Stack2_Logger(),
            self::SITE_ID
        );
        $controller = new Stack2_REST_Controller(
            new Stack2_Signature_Service(),
            $executor,
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

if (!class_exists('WP_Plugin_Dependencies')) {
    /**
     * Test double for WordPress 6.5 WP_Plugin_Dependencies.
     *
     * initialize() reads Requires Plugins locally, then calls plugins_api()
     * so a collector that forgets to block WordPress.org fails the HTTP test.
     */
    class WP_Plugin_Dependencies
    {
        /** @var array<int, string> */
        public static $log = array();

        /** @var array<string, array<int, string>> */
        private static $dependencies = array();

        /** @var array<string, string> */
        private static $slugs = array();

        public static function reset(): void
        {
            self::$log = array();
            self::$dependencies = array();
            self::$slugs = array();
        }

        public static function initialize(): void
        {
            self::$log[] = 'initialize';
            self::$dependencies = array();
            self::$slugs = array();
            $plugins = function_exists('get_plugins') ? get_plugins() : array();

            foreach ($plugins as $file => $header) {
                $raw = isset($header['RequiresPlugins']) ? (string) $header['RequiresPlugins'] : '';
                $slugs = array();
                foreach (explode(',', $raw) as $slug) {
                    $slug = trim($slug);
                    if ($slug !== '' && preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/', $slug)) {
                        $slugs[] = $slug;
                    }
                }
                $slugs = array_values(array_unique($slugs));
                sort($slugs);
                self::$dependencies[(string) $file] = $slugs;
                $file = (string) $file;
                self::$slugs[$file] = str_contains($file, '/')
                    ? dirname($file)
                    : str_replace('.php', '', $file);
            }

            if (function_exists('plugins_api')) {
                plugins_api('plugin_information', array('slug' => 'woocommerce'));
            } else {
                wp_remote_get('https://api.wordpress.org/plugins/info/1.2/');
            }
        }

        /**
         * @return array<int, string>
         */
        public static function get_dependencies($plugin_file): array
        {
            self::$log[] = 'get_dependencies:' . $plugin_file;

            return self::$dependencies[(string) $plugin_file] ?? array();
        }

        public static function has_circular_dependency($plugin_file): bool
        {
            self::$log[] = 'has_circular_dependency:' . $plugin_file;
            $plugin_file = (string) $plugin_file;
            $slug = self::$slugs[$plugin_file] ?? '';
            $dependencies = self::$dependencies[$plugin_file] ?? array();
            if ($slug !== '' && in_array($slug, $dependencies, true)) {
                return true;
            }

            foreach ($dependencies as $dependency_slug) {
                $dependency_file = array_search($dependency_slug, self::$slugs, true);
                if ($dependency_file === false) {
                    continue;
                }
                $dependency_dependencies = self::$dependencies[$dependency_file] ?? array();
                if (in_array($slug, $dependency_dependencies, true)) {
                    return true;
                }
            }

            return false;
        }
    }
}
