<?php

use PHPUnit\Framework\TestCase;

class CoreUpdateCommandTest extends TestCase
{
    private const SITE_ID = 'site_test';
    private const API_KEY = 'secret-key';
    private const PIN = '6.8.5';
    private const NEWEST = '6.8.10';
    private const BEFORE = '6.8.2';

    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['stack2_options'] = array(
            Stack2_Plugin::OPTION_BASE_URL => 'https://app.stack2.au',
            Stack2_Plugin::OPTION_SITE_ID => self::SITE_ID,
            Stack2_Plugin::OPTION_API_KEY => self::API_KEY,
        );
        $GLOBALS['stack2_wp_version'] = self::BEFORE;
        $GLOBALS['stack2_locale'] = 'en_US';
        $GLOBALS['stack2_plugins'] = array();
        $GLOBALS['stack2_core_upgrader_calls'] = array();
        $GLOBALS['stack2_core_upgrader_upgrade'] = null;
        $GLOBALS['stack2_wp_version_check_calls'] = 0;
        $GLOBALS['stack2_get_core_updates_calls'] = 0;
        $GLOBALS['stack2_wp_version_check_impl'] = null;
        $GLOBALS['stack2_outbound_http'] = array();
        $GLOBALS['stack2_http_head'] = null;
        $GLOBALS['stack2_upgrader_skin_messages'] = array();
        $GLOBALS['wp_filesystem'] = null;
        wp_mkdir_p(ABSPATH);
        $this->delete_version_file();
        $this->delete_maintenance_file();
    }

    protected function tearDown(): void
    {
        $this->delete_version_file();
        $this->delete_maintenance_file();
        $GLOBALS['stack2_core_upgrader_upgrade'] = null;
        $GLOBALS['stack2_http_head'] = null;
        $GLOBALS['stack2_locale'] = 'en_US';
        $GLOBALS['wp_filesystem'] = null;
        parent::tearDown();
    }

    public function test_pinned_intermediate_is_the_package_not_the_version_check_tip(): void
    {
        $GLOBALS['stack2_core_upgrader_upgrade'] = static function ($current) {
            $full = (string) ($current->packages->full ?? '');
            $partial = (string) ($current->packages->partial ?? '');
            if ($current->version !== self::PIN || $full !== self::english_zip(self::PIN) || $partial !== '') {
                $GLOBALS['stack2_wp_version'] = self::NEWEST;

                return self::NEWEST;
            }
            $GLOBALS['stack2_wp_version'] = self::PIN;

            return self::PIN;
        };

        $result = $this->executor()->execute('update_core', null, null, array('version' => self::PIN));

        $this->assertTrue($result['success']);
        $this->assertNull($result['error']);
        $this->assertNull($result['error_message']);
        $this->assertFalse($result['not_applied']);
        $this->assertSame(self::PIN, $result['installed_version']);
        $this->assertSame(self::PIN, $result['inventory']['wp_version']);
        $this->assertArrayNotHasKey('core_update', $result['inventory']);
        $this->assertArrayNotHasKey('error_code', $result);
        $this->assertArrayNotHasKey('plugin_version', $result);
        $this->assertSame(0, $GLOBALS['stack2_wp_version_check_calls']);
        $this->assertSame(0, $GLOBALS['stack2_get_core_updates_calls']);
        $this->assertCount(1, $GLOBALS['stack2_core_upgrader_calls']);

        $call = $GLOBALS['stack2_core_upgrader_calls'][0];
        $this->assertSame(self::PIN, $call['current']->version);
        $this->assertSame(self::english_zip(self::PIN), $call['current']->packages->full);
        $this->assertSame(self::english_zip(self::PIN), $call['current']->download);
        $this->assertSame('', $call['current']->packages->partial);
        $this->assertSame('', $call['current']->packages->no_content);
        $this->assertSame('', $call['current']->packages->new_bundled);
        $this->assertSame('', $call['current']->packages->rollback);
        $this->assertFalse($call['args']['pre_check_md5']);
        $this->assertFalse($call['args']['attempt_rollback']);
        $this->assertFalse($call['args']['do_rollback']);
        $this->assertNoVersionCheckRequest();
    }

    public function test_fake_that_installs_the_newest_offer_fails_the_pin(): void
    {
        $GLOBALS['stack2_core_upgrader_upgrade'] = static function () {
            $GLOBALS['stack2_wp_version'] = self::NEWEST;

            return self::NEWEST;
        };

        $result = $this->executor()->execute('update_core', null, null, array('version' => self::PIN));

        $this->assertFalse($result['success']);
        $this->assertSame('version_mismatch', $result['error_code']);
        $this->assertFalse($result['not_applied']);
        $this->assertSame(self::NEWEST, $result['installed_version']);
        $this->assertSame(self::NEWEST, $result['inventory']['wp_version']);
        $this->assertSame(self::PIN, $GLOBALS['stack2_core_upgrader_calls'][0]['current']->version);
        $this->assertSame(self::english_zip(self::PIN), $GLOBALS['stack2_core_upgrader_calls'][0]['current']->packages->full);
        $this->assertStringNotContainsString('partial', $GLOBALS['stack2_core_upgrader_calls'][0]['current']->packages->full);
        $this->assertSame(0, $GLOBALS['stack2_wp_version_check_calls']);
        $this->assertSame(0, $GLOBALS['stack2_get_core_updates_calls']);
    }

    public function test_upgrader_return_string_is_not_success_when_wp_version_stays_behind(): void
    {
        $GLOBALS['stack2_core_upgrader_upgrade'] = static function () {
            return self::PIN;
        };

        $result = $this->executor()->execute('update_core', null, null, array('version' => self::PIN));

        $this->assertFalse($result['success']);
        $this->assertTrue($result['not_applied']);
        $this->assertSame('not_applied', $result['error_code']);
        $this->assertSame(self::BEFORE, $result['installed_version']);
        $this->assertSame(self::BEFORE, $result['inventory']['wp_version']);
    }

    public function test_disk_version_overrides_the_stale_request_global(): void
    {
        $GLOBALS['stack2_core_upgrader_upgrade'] = static function () {
            self::write_version_file(self::PIN);

            return self::PIN;
        };

        $result = $this->executor()->execute('update_core', null, null, array('version' => self::PIN));

        $this->assertTrue($result['success']);
        $this->assertSame(self::PIN, $result['installed_version']);
        $this->assertSame(self::PIN, $result['inventory']['wp_version']);
        $this->assertSame(self::PIN, $GLOBALS['wp_version']);
    }

    public function test_xy_release_uses_the_full_zip_for_that_version(): void
    {
        $GLOBALS['stack2_core_upgrader_upgrade'] = static function ($current) {
            $GLOBALS['stack2_wp_version'] = (string) $current->version;

            return $current->version;
        };

        $result = $this->executor()->execute('update_core', null, null, array('version' => '6.8'));

        $this->assertTrue($result['success']);
        $this->assertSame('6.8', $result['installed_version']);
        $this->assertSame(self::english_zip('6.8'), $GLOBALS['stack2_core_upgrader_calls'][0]['current']->packages->full);
    }

    public function test_locale_package_is_used_when_the_zip_exists(): void
    {
        $GLOBALS['stack2_locale'] = 'de_DE';
        $GLOBALS['stack2_http_head'] = static function (string $url) {
            $code = str_contains($url, '/de_DE/wordpress-' . self::PIN . '.zip') ? 200 : 404;

            return array('response' => array('code' => $code));
        };
        $GLOBALS['stack2_core_upgrader_upgrade'] = static function ($current) {
            $GLOBALS['stack2_wp_version'] = (string) $current->version;

            return $current->version;
        };

        $result = $this->executor()->execute('update_core', null, null, array('version' => self::PIN));

        $this->assertTrue($result['success']);
        $this->assertSame(self::PIN, $result['installed_version']);
        $this->assertCount(1, $GLOBALS['stack2_core_upgrader_calls']);
        $this->assertSame(
            'https://downloads.wordpress.org/release/de_DE/wordpress-' . self::PIN . '.zip',
            $GLOBALS['stack2_core_upgrader_calls'][0]['current']->packages->full
        );
        $this->assertNoVersionCheckRequest();
    }

    public function test_missing_locale_package_falls_back_to_en_us_zip(): void
    {
        $GLOBALS['stack2_locale'] = 'de_DE';
        $GLOBALS['stack2_http_head'] = static function () {
            return array('response' => array('code' => 404));
        };
        $GLOBALS['stack2_core_upgrader_upgrade'] = static function ($current) {
            $GLOBALS['stack2_wp_version'] = (string) $current->version;

            return $current->version;
        };

        $result = $this->executor()->execute('update_core', null, null, array('version' => self::PIN));

        $this->assertTrue($result['success']);
        $this->assertCount(1, $GLOBALS['stack2_core_upgrader_calls']);
        $this->assertSame(self::english_zip(self::PIN), $GLOBALS['stack2_core_upgrader_calls'][0]['current']->packages->full);
    }

    public function test_locale_download_failure_retries_the_same_pin_in_en_us(): void
    {
        $GLOBALS['stack2_locale'] = 'de_DE';
        $GLOBALS['stack2_http_head'] = static function () {
            return new WP_Error('http_request_failed', 'timed out');
        };
        $GLOBALS['stack2_core_upgrader_upgrade'] = static function ($current) {
            $full = (string) $current->packages->full;
            if (str_contains($full, '/de_DE/')) {
                return new WP_Error('download_failed', 'Download failed. Not Found');
            }
            $GLOBALS['stack2_wp_version'] = (string) $current->version;

            return $current->version;
        };

        $result = $this->executor()->execute('update_core', null, null, array('version' => self::PIN));

        $this->assertTrue($result['success']);
        $this->assertSame(self::PIN, $result['installed_version']);
        $this->assertCount(2, $GLOBALS['stack2_core_upgrader_calls']);
        $this->assertSame(
            'https://downloads.wordpress.org/release/de_DE/wordpress-' . self::PIN . '.zip',
            $GLOBALS['stack2_core_upgrader_calls'][0]['current']->packages->full
        );
        $this->assertSame(self::english_zip(self::PIN), $GLOBALS['stack2_core_upgrader_calls'][1]['current']->packages->full);
        $this->assertSame(0, $GLOBALS['stack2_get_core_updates_calls']);
    }

    public function test_unsafe_locale_does_not_change_the_package_path(): void
    {
        $GLOBALS['stack2_locale'] = '../de_DE';
        $GLOBALS['stack2_core_upgrader_upgrade'] = static function ($current) {
            $GLOBALS['stack2_wp_version'] = (string) $current->version;

            return $current->version;
        };

        $this->executor()->execute('update_core', null, null, array('version' => self::PIN));

        $this->assertSame(self::english_zip(self::PIN), $GLOBALS['stack2_core_upgrader_calls'][0]['current']->packages->full);
        $this->assertSame(array(), $GLOBALS['stack2_outbound_http']);
    }

    /**
     * @dataProvider invalidVersions
     */
    public function test_non_release_versions_are_rejected(string $version): void
    {
        $result = $this->executor()->execute('update_core', null, null, array('version' => $version));

        $this->assertFalse($result['success']);
        $this->assertSame('invalid_version', $result['error_code']);
        $this->assertFalse($result['not_applied']);
        $this->assertSame($result['error'], $result['error_message']);
        $this->assertSame(array(), $GLOBALS['stack2_core_upgrader_calls']);
        $this->assertSame(0, $GLOBALS['stack2_wp_version_check_calls']);
    }

    public function test_rejected_command_still_removes_maintenance_file(): void
    {
        $this->write_maintenance_file();

        $result = $this->executor()->execute('update_core', null, null, array('version' => '6.8-RC1'));

        $this->assertSame('invalid_version', $result['error_code']);
        $this->assertFileDoesNotExist($this->maintenance_path());
    }

    public function test_upgrader_failure_keeps_wordpress_reason_and_clears_maintenance(): void
    {
        $GLOBALS['stack2_upgrader_skin_messages'] = array('Could not copy files.');
        $GLOBALS['stack2_core_upgrader_upgrade'] = static function () {
            self::write_maintenance_file();

            return new WP_Error('copy_failed', 'Could not copy files.');
        };

        $result = $this->executor()->execute('update_core', null, null, array('version' => self::PIN));

        $this->assertFalse($result['success']);
        $this->assertSame('copy_failed', $result['error_code']);
        $this->assertSame('Could not copy files.', $result['error']);
        $this->assertSame('Could not copy files.', $result['error_message']);
        $this->assertFalse($result['not_applied']);
        $this->assertSame(array('Could not copy files.'), $result['skin_messages']);
        $this->assertSame(self::BEFORE, $result['installed_version']);
        $this->assertFileDoesNotExist($this->maintenance_path());
    }

    public function test_empty_upgrader_result_uses_filesystem_fallback(): void
    {
        $GLOBALS['stack2_core_upgrader_upgrade'] = static function () {
            return false;
        };

        $result = $this->executor()->execute('update_core', null, null, array('version' => self::PIN));

        $this->assertSame('Core update failed. Filesystem credentials may be required.', $result['error']);
        $this->assertSame('fs_credentials', $result['error_code']);
        $this->assertFalse($result['not_applied']);
    }

    public function test_thrown_upgrader_clears_maintenance_and_hides_package_url(): void
    {
        $url = self::english_zip(self::PIN);
        $log = $this->capture_log();
        $GLOBALS['stack2_core_upgrader_upgrade'] = static function () use ($url) {
            self::write_maintenance_file();
            throw new RuntimeException('exploded ' . $url);
        };

        try {
            $result = $this->executor()->execute('update_core', null, null, array('version' => self::PIN));
        } finally {
            $this->restore_log($log['previous']);
        }

        $this->assertFalse($result['success']);
        $this->assertSame('update_failed', $result['error_code']);
        $this->assertSame('Core update failed.', $result['error']);
        $this->assertStringNotContainsString('downloads.wordpress.org', $result['error']);
        $this->assertFileDoesNotExist($this->maintenance_path());
        $written = (string) file_get_contents($log['path']);
        $this->assertStringContainsString('[package-url]', $written);
        $this->assertStringNotContainsString('downloads.wordpress.org', $written);
        $this->assertStringNotContainsString('downloads.w.org', $written);
    }

    public function test_signed_command_returns_the_pin_and_omits_the_package_url_from_the_log(): void
    {
        $url = self::english_zip(self::PIN);
        $log = $this->capture_log();
        $GLOBALS['stack2_upgrader_skin_messages'] = array('Downloading update from ' . $url);
        $GLOBALS['stack2_core_upgrader_upgrade'] = static function () use ($url) {
            return new WP_Error('download_failed', 'Download failed. ' . $url);
        };

        try {
            $failed = $this->signed_command(array('action' => 'update_core', 'version' => self::PIN));
        } finally {
            $this->restore_log($log['previous']);
        }

        $this->assertSame(400, $failed['status_code']);
        $this->assertFalse($failed['body']['success']);
        $this->assertSame('download_failed', $failed['body']['error_code']);
        $this->assertSame('Download failed. ' . $url, $failed['body']['error']);
        $this->assertSame($failed['body']['error'], $failed['body']['error_message']);
        $this->assertSame(array('Downloading update from ' . $url), $failed['body']['skin_messages']);
        $this->assertFalse($failed['body']['not_applied']);
        $this->assertSame(self::BEFORE, $failed['body']['installed_version']);
        $this->assertSame(self::BEFORE, $failed['body']['inventory']['wp_version']);
        $written = (string) file_get_contents($log['path']);
        $this->assertStringNotContainsString('downloads.wordpress.org', $written);
        $this->assertStringNotContainsString('downloads.w.org', $written);
        $this->assertStringContainsString('[package-url]', $written);

        $GLOBALS['stack2_upgrader_skin_messages'] = array();
        $GLOBALS['stack2_core_upgrader_calls'] = array();
        $GLOBALS['stack2_core_upgrader_upgrade'] = static function ($current) {
            $GLOBALS['stack2_wp_version'] = (string) $current->version;

            return $current->version;
        };
        $ok = $this->signed_command(array('action' => 'update_core', 'version' => self::PIN));

        $this->assertSame(200, $ok['status_code']);
        $this->assertTrue($ok['body']['success']);
        $this->assertNull($ok['body']['error']);
        $this->assertNull($ok['body']['error_message']);
        $this->assertFalse($ok['body']['not_applied']);
        $this->assertSame(self::PIN, $ok['body']['installed_version']);
        $this->assertSame(self::PIN, $ok['body']['inventory']['wp_version']);
        $this->assertArrayNotHasKey('error_code', $ok['body']);
        $this->assertArrayNotHasKey('plugin_version', $ok['body']);
    }

    public function test_signed_command_rejects_a_non_string_version(): void
    {
        $data = $this->signed_command(array('action' => 'update_core', 'version' => 6.8));

        $this->assertSame(400, $data['status_code']);
        $this->assertSame('invalid_version', $data['body']['error_code']);
        $this->assertSame(array(), $GLOBALS['stack2_core_upgrader_calls']);
    }

    public function test_plugin_update_does_not_invoke_core_upgrader(): void
    {
        $GLOBALS['stack2_plugins'] = array(
            'hello.php' => array(
                'Name' => 'Hello',
                'Version' => '1.0',
                'Author' => 'Test',
                'PluginURI' => '',
                'Description' => '',
            ),
        );
        $GLOBALS['stack2_plugin_upgrader_bulk'] = static function (array $plugins) {
            return array($plugins[0] => array('destination' => WP_PLUGIN_DIR));
        };

        $result = $this->executor()->execute('update', 'hello.php', null, array('version' => self::PIN));

        $this->assertFalse($result['success']);
        $this->assertSame('not_applied', $result['error_code']);
        $this->assertArrayNotHasKey('installed_version', $result);
        $this->assertSame(array(), $GLOBALS['stack2_core_upgrader_calls']);
        $GLOBALS['stack2_plugin_upgrader_bulk'] = null;
    }

    public function test_filesystem_delete_is_used_for_maintenance(): void
    {
        $deleted = array();
        $GLOBALS['wp_filesystem'] = new class($deleted) {
            public array $deleted;

            public function __construct(array &$deleted)
            {
                $this->deleted = &$deleted;
            }

            public function abspath()
            {
                return ABSPATH;
            }

            public function delete($path)
            {
                $this->deleted[] = $path;

                return true;
            }
        };
        $GLOBALS['stack2_core_upgrader_upgrade'] = static function () {
            self::write_maintenance_file();
            $GLOBALS['stack2_wp_version'] = self::PIN;

            return self::PIN;
        };

        $result = $this->executor()->execute('update_core', null, null, array('version' => self::PIN));

        $this->assertTrue($result['success']);
        $this->assertNotEmpty($deleted);
        $this->assertStringEndsWith('.maintenance', $deleted[0]);
        $this->assertFileDoesNotExist($this->maintenance_path());
    }

    public static function invalidVersions(): array
    {
        return array(
            'empty' => array(''),
            'major only' => array('6'),
            'four parts' => array('6.8.5.1'),
            'beta' => array('6.8-beta1'),
            'rc' => array('6.8-RC1'),
            'rc patch' => array('6.8.5-RC2'),
            'nightly' => array('nightly'),
            'alpha' => array('6.8.5-alpha'),
            'latest' => array('latest'),
            'v prefix' => array('v6.8.5'),
            'leading zero' => array('6.08.5'),
            'path' => array('../6.8.5'),
        );
    }

    private static function english_zip(string $version): string
    {
        return 'https://downloads.wordpress.org/release/wordpress-' . $version . '.zip';
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

    private function assertNoVersionCheckRequest(): void
    {
        $urls = array();
        foreach ($GLOBALS['stack2_outbound_http'] as $call) {
            $urls[] = (string) ($call['url'] ?? '');
        }
        $joined = implode(' ', $urls);
        $this->assertStringNotContainsString('version-check', $joined);
        $this->assertStringNotContainsString('api.wordpress.org', $joined);
        $this->assertStringNotContainsString(self::NEWEST, $joined);
        $this->assertStringNotContainsString('partial', $joined);
    }

    private function maintenance_path(): string
    {
        return trailingslashit(ABSPATH) . '.maintenance';
    }

    private function version_path(): string
    {
        return rtrim(ABSPATH, '/\\') . '/wp-includes/version.php';
    }

    private function delete_maintenance_file(): void
    {
        $path = $this->maintenance_path();
        if (is_file($path)) {
            unlink($path);
        }
    }

    private function delete_version_file(): void
    {
        $path = $this->version_path();
        if (is_file($path)) {
            unlink($path);
        }
    }

    private static function write_maintenance_file(): void
    {
        wp_mkdir_p(ABSPATH);
        file_put_contents(trailingslashit(ABSPATH) . '.maintenance', "<?php \$upgrading = 1; ?>\n");
    }

    private static function write_version_file(string $version): void
    {
        $path = rtrim(ABSPATH, '/\\') . '/wp-includes/version.php';
        wp_mkdir_p(dirname($path));
        file_put_contents($path, "<?php\n\$wp_version = '" . $version . "';\n");
    }

    /**
     * @return array{path: string, previous: string|false}
     */
    private function capture_log(): array
    {
        $path = sys_get_temp_dir() . '/stack2-core-update-' . getmypid() . '.log';
        if (is_file($path)) {
            unlink($path);
        }
        $previous = ini_get('error_log');
        ini_set('log_errors', '1');
        ini_set('error_log', $path);

        return array('path' => $path, 'previous' => $previous);
    }

    /**
     * @param string|false $previous
     */
    private function restore_log($previous): void
    {
        ini_set('error_log', $previous === false ? '' : $previous);
    }
}
