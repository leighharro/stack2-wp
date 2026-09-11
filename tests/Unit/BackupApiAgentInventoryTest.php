<?php

require_once __DIR__ . '/BackupTestCase.php';

class BackupApiAgentInventoryTest extends BackupTestCase
{
    public function test_initiate_returns_agent_mode_without_file_list(): void
    {
        for ($i = 0; $i < 50; $i++) {
            $this->write_file('wp-content/uploads/n-' . $i . '.txt', str_repeat('x', 100));
        }

        $api = $this->api($this->manager());
        $body = wp_json_encode(array(
            'backup_id' => '550e8400-e29b-41d4-a716-446655440000',
            'job_id' => 'backup_agent_init',
            'include_files' => true,
            'include_database' => true,
            'timestamp' => '2026-09-04T12:00:00Z',
        ));

        $request = new WP_REST_Request();
        $this->sign_request($request, 'POST', '/wp-json/stack2/v1/backups/initiate', $body);

        $response = $api->initiate_backup($request);
        $this->assertSame(200, $response->get_status());

        $data = $response->get_data();
        $encoded = wp_json_encode($data);
        $this->assertNotFalse($encoded);
        $this->assertLessThan(8000, strlen($encoded));

        $this->assertTrue($data['success']);
        $this->assertSame('initiated', $data['status']);
        $this->assertSame('agent', $data['manifest_mode']);
        $this->assertSame(500, $data['scan']['default_limit']);
        $this->assertSame(2000, $data['scan']['max_limit']);
        $this->assertSame(50, $data['stats']['default_batch']);
        $this->assertSame(200, $data['stats']['max_batch']);
        $this->assertArrayNotHasKey('manifest_status', $data);
        $this->assertArrayNotHasKey('files_page_size', $data);
        $this->assertSame('backup_agent_init', $data['job_id']);
        $this->assertSame(array(), $data['manifest']['files']);
        $this->assertSame('agent', $data['manifest']['manifest_mode']);
        $this->assertTrue($data['manifest']['manifest_complete']);
        $this->assertSame(0, $data['manifest']['estimated_files_count']);
        $this->assertSame(array('wp_options', 'wp_posts'), $data['manifest']['tables']);
        $this->assertContains(trailingslashit(wp_normalize_path(ABSPATH)), $data['manifest']['source_paths']);
        $this->assertArrayHasKey('upload_path', $data['manifest']);
        $this->assertArrayHasKey('upload_url_path', $data['manifest']);
        $this->assertArrayHasKey('connector_version', $data['manifest']);
        $this->assertSame(STACK2_CONNECTOR_VERSION, $data['manifest']['connector_version']);
        $this->assertSame(array(), $GLOBALS['stack2_cron']);
    }

    public function test_initiate_singular_alias_and_include_files_false(): void
    {
        $this->write_file('wp-content/uploads/hidden.txt', 'nope');

        $api = $this->api($this->manager());
        $body = wp_json_encode(array(
            'job_id' => 'backup_db_only',
            'include_files' => false,
            'include_database' => true,
        ));

        $request = new WP_REST_Request();
        $this->sign_request($request, 'POST', '/wp-json/stack2/v1/backup/initiate', $body);

        $response = $api->initiate_backup($request);
        $data = $response->get_data();

        $this->assertSame(200, $response->get_status());
        $this->assertSame('agent', $data['manifest_mode']);
        $this->assertSame(array(), $data['manifest']['files']);
        $this->assertTrue($data['manifest']['manifest_complete']);
    }

    public function test_initiate_rejects_bad_hmac(): void
    {
        $api = $this->api($this->manager());
        $body = wp_json_encode(array(
            'include_files' => true,
            'include_database' => false,
        ));

        $request = new WP_REST_Request();
        $request->set_header('x-stack2-site-id', 'site_test');
        $request->set_header('x-stack2-timestamp', (string) time());
        $request->set_header('x-stack2-signature', str_repeat('a', 64));
        $request->set_body($body);
        $request->set_route('/wp-json/stack2/v1/backups/initiate');

        $response = $api->initiate_backup($request);
        $this->assertSame(401, $response->get_status());
        $this->assertFalse($response->get_data()['success']);
    }

    public function test_scan_pages_all_entries_with_signed_auth(): void
    {
        $files = array(
            'wp-content/uploads/one.txt' => 'one-body',
            'wp-content/uploads/two.txt' => 'two-body',
            'wp-content/plugins/demo/main.php' => '<?php',
        );
        $this->create_tree($files);

        $manager = $this->manager();
        $prepared = $manager->prepare_backup('b2', true, false, gmdate('c'), 'job_api_scan');
        $api = $this->api($manager);

        $collected = array();
        $cursor = '';
        for ($i = 0; $i < 20; $i++) {
            $request = new WP_REST_Request();
            $path = '/wp-json/stack2/v1/backups/job_api_scan/files/scan';
            $this->sign_request($request, 'GET', $path, '');
            $request->set_param('job_id', $prepared['job_id']);
            $request->set_param('cursor', $cursor);
            $request->set_param('limit', 2);

            $response = $api->scan_files($request);
            $this->assertSame(200, $response->get_status());
            $payload = $response->get_data();
            $this->assertTrue($payload['success']);
            $this->assertSame('job_api_scan', $payload['job_id']);
            foreach ($payload['entries'] as $entry) {
                $this->assertArrayNotHasKey('sha256', $entry);
                $collected[] = $entry;
            }

            if (empty($payload['has_more'])) {
                $this->assertNull($payload['next_cursor']);
                break;
            }

            $cursor = (string) $payload['next_cursor'];
        }

        $this->assertSame($this->expected_scan_entries($files), $this->sort_entries_by_path($collected));
    }

    public function test_scan_hmac_rejection_and_unknown_job(): void
    {
        $manager = $this->manager();
        $manager->prepare_backup('b1', true, false, gmdate('c'), 'job_hmac');
        $api = $this->api($manager);

        $bad = new WP_REST_Request();
        $bad->set_header('x-stack2-site-id', 'site_test');
        $bad->set_header('x-stack2-timestamp', (string) time());
        $bad->set_header('x-stack2-signature', str_repeat('b', 64));
        $bad->set_route('/wp-json/stack2/v1/backups/job_hmac/files/scan');
        $bad->set_param('job_id', 'job_hmac');
        $bad->set_param('cursor', '');
        $bad->set_param('limit', 10);

        $denied = $api->scan_files($bad);
        $this->assertSame(401, $denied->get_status());
        $this->assertFalse($denied->get_data()['success']);

        $missing = new WP_REST_Request();
        $this->sign_request($missing, 'GET', '/wp-json/stack2/v1/backup/missing_job/files/scan', '');
        $missing->set_param('job_id', 'missing_job');
        $missing->set_param('limit', 10);

        $response = $api->scan_files($missing);
        $this->assertSame(404, $response->get_status());
        $this->assertFalse($response->get_data()['success']);
    }

    public function test_stats_signed_post_reports_missing_paths(): void
    {
        $this->write_file('wp-content/uploads/keep.txt', 'keep-body');

        $manager = $this->manager();
        $manager->prepare_backup('b3', true, false, gmdate('c'), 'job_stats');
        $api = $this->api($manager);

        $body = wp_json_encode(array(
            'paths' => array(
                'wp-content/uploads/keep.txt',
                'wp-content/uploads/missing.txt',
            ),
            'include_sha256' => true,
        ));

        $request = new WP_REST_Request();
        $this->sign_request($request, 'POST', '/wp-json/stack2/v1/backups/job_stats/files/stats', $body);
        $request->set_param('job_id', 'job_stats');

        $response = $api->stat_files($request);
        $this->assertSame(200, $response->get_status());
        $data = $response->get_data();
        $this->assertTrue($data['success']);
        $this->assertCount(1, $data['stats']);
        $this->assertSame('wp-content/uploads/keep.txt', $data['stats'][0]['path']);
        $this->assertSame(hash('sha256', 'keep-body'), $data['stats'][0]['sha256']);
        $this->assertSame(array('wp-content/uploads/missing.txt'), $data['missing']);
        $this->assertSame(array(), $data['failed']);
    }

    public function test_stats_rejects_over_max_and_bad_hmac(): void
    {
        $manager = $this->manager();
        $manager->prepare_backup('b4', true, false, gmdate('c'), 'job_stats_max');
        $api = $this->api($manager);

        $paths = array();
        for ($i = 0; $i < 201; $i++) {
            $paths[] = 'wp-content/uploads/f-' . $i . '.txt';
        }
        $body = wp_json_encode(array('paths' => $paths, 'include_sha256' => true));

        $request = new WP_REST_Request();
        $this->sign_request($request, 'POST', '/wp-json/stack2/v1/backup/job_stats_max/files/stats', $body);
        $request->set_param('job_id', 'job_stats_max');

        $response = $api->stat_files($request);
        $this->assertSame(400, $response->get_status());
        $this->assertSame('Too many paths. Maximum is 200.', $response->get_data()['error']);

        $bad = new WP_REST_Request();
        $bad->set_header('x-stack2-site-id', 'site_test');
        $bad->set_header('x-stack2-timestamp', (string) time());
        $bad->set_header('x-stack2-signature', str_repeat('c', 64));
        $bad->set_body('{"paths":[]}');
        $bad->set_route('/wp-json/stack2/v1/backups/job_stats_max/files/stats');
        $bad->set_param('job_id', 'job_stats_max');

        $denied = $api->stat_files($bad);
        $this->assertSame(401, $denied->get_status());
    }

    public function test_alternate_path_normalization_accepts_singular_scan_signature(): void
    {
        $manager = $this->manager();
        $manager->prepare_backup('b5', false, true, gmdate('c'), 'job_alias');
        $api = $this->api($manager);

        $request = new WP_REST_Request();
        $this->sign_request($request, 'GET', '/wp-json/stack2/v1/backup/job_alias/files/scan', '');
        $request->set_route('/wp-json/stack2/v1/backups/job_alias/files/scan');
        $request->set_param('job_id', 'job_alias');
        $request->set_param('limit', 10);

        $response = $api->scan_files($request);
        $this->assertSame(200, $response->get_status());
        $this->assertTrue($response->get_data()['success']);
    }

    public function test_scan_and_stats_apply_signed_body_exclude_patterns(): void
    {
        $this->create_tree(array(
            'wp-content/uploads/keep.txt' => 'keep-me',
            'wp-content/custom-skip/secret.txt' => 'platform-only',
            'wp-content/cache/generated.txt' => 'local-default-only',
            'wp-content/themes/Divi/core/components/cache/Directory.php' => '<?php',
        ));

        $manager = $this->manager();
        $manager->prepare_backup('b6', true, false, gmdate('c'), 'job_excludes');
        $api = $this->api($manager);

        $scan_body = wp_json_encode(array(
            'exclude_patterns' => array('/custom-skip/'),
            'unknown_future_key' => true,
        ));
        $scan = new WP_REST_Request();
        $scan->set_method('POST');
        $this->sign_request($scan, 'POST', '/wp-json/stack2/v1/backups/job_excludes/files/scan', $scan_body);
        $scan->set_param('job_id', 'job_excludes');
        $scan->set_param('limit', 50);

        $scan_response = $api->scan_files($scan);
        $this->assertSame(200, $scan_response->get_status());
        $paths = array_column($this->sort_entries_by_path($scan_response->get_data()['entries']), 'path');
        $this->assertSame(
            array(
                'wp-content/cache/generated.txt',
                'wp-content/themes/Divi/core/components/cache/Directory.php',
                'wp-content/uploads/keep.txt',
            ),
            $paths
        );

        $stats_body = wp_json_encode(array(
            'paths' => array(
                'wp-content/uploads/keep.txt',
                'wp-content/custom-skip/secret.txt',
                'wp-content/cache/generated.txt',
            ),
            'include_sha256' => true,
            'exclude_patterns' => array('/custom-skip/'),
            'unknown_future_key' => array('ok' => 1),
        ));
        $stats = new WP_REST_Request();
        $stats->set_method('POST');
        $this->sign_request($stats, 'POST', '/wp-json/stack2/v1/backups/job_excludes/files/stats', $stats_body);
        $stats->set_param('job_id', 'job_excludes');

        $stats_response = $api->stat_files($stats);
        $this->assertSame(200, $stats_response->get_status());
        $data = $stats_response->get_data();
        $this->assertSame(
            array(
                'wp-content/uploads/keep.txt',
                'wp-content/cache/generated.txt',
            ),
            array_column($data['stats'], 'path')
        );
        $this->assertSame(array('wp-content/custom-skip/secret.txt'), $data['missing']);
    }

    public function test_scan_prefers_hmac_body_exclude_patterns_over_query(): void
    {
        $this->create_tree(array(
            'wp-content/uploads/keep.txt' => 'keep-me',
            'wp-content/body-skip/a.txt' => 'from-body',
            'wp-content/query-skip/b.txt' => 'from-query',
        ));

        $manager = $this->manager();
        $manager->prepare_backup('b7', true, false, gmdate('c'), 'job_prefers_body');
        $api = $this->api($manager);

        $body = wp_json_encode(array('exclude_patterns' => array('/body-skip/')));
        $request = new WP_REST_Request();
        $request->set_method('POST');
        $this->sign_request($request, 'POST', '/wp-json/stack2/v1/backups/job_prefers_body/files/scan', $body);
        $request->set_param('job_id', 'job_prefers_body');
        $request->set_param('limit', 50);
        $request->set_param('exclude_patterns', array('/query-skip/'));

        $response = $api->scan_files($request);
        $paths = array_column($this->sort_entries_by_path($response->get_data()['entries']), 'path');
        $this->assertSame(
            array(
                'wp-content/query-skip/b.txt',
                'wp-content/uploads/keep.txt',
            ),
            $paths
        );
    }

    public function test_scan_omitted_exclude_patterns_uses_local_defaults(): void
    {
        $this->create_tree(array(
            'wp-content/themes/Divi/core/components/cache/Directory.php' => '<?php',
            'wp-content/cache/object/x' => 'generated',
            'wp-content/uploads/keep.txt' => 'keep-me',
        ));

        $manager = $this->manager();
        $manager->prepare_backup('b8', true, false, gmdate('c'), 'job_default_excludes');
        $api = $this->api($manager);

        $request = new WP_REST_Request();
        $this->sign_request($request, 'GET', '/wp-json/stack2/v1/backups/job_default_excludes/files/scan', '');
        $request->set_param('job_id', 'job_default_excludes');
        $request->set_param('limit', 50);

        $response = $api->scan_files($request);
        $this->assertSame(200, $response->get_status());
        $this->assertSame(
            array(
                'wp-content/themes/Divi/core/components/cache/Directory.php',
                'wp-content/uploads/keep.txt',
            ),
            array_column($this->sort_entries_by_path($response->get_data()['entries']), 'path')
        );
    }

    public function test_empty_exclude_patterns_body_is_not_disable(): void
    {
        $this->create_tree(array(
            'wp-content/uploads/keep.txt' => 'keep-me',
            'wp-content/cache/generated.txt' => 'local-default',
            'wp-content/debug.log' => 'wp-debug',
        ));

        $manager = $this->manager();
        $manager->prepare_backup('b9', true, false, gmdate('c'), 'job_empty_patterns');
        $api = $this->api($manager);

        $body = wp_json_encode(array('exclude_patterns' => array()));
        $request = new WP_REST_Request();
        $request->set_method('POST');
        $this->sign_request($request, 'POST', '/wp-json/stack2/v1/backups/job_empty_patterns/files/scan', $body);
        $request->set_param('job_id', 'job_empty_patterns');
        $request->set_param('limit', 50);

        $response = $api->scan_files($request);
        $this->assertSame(200, $response->get_status());
        $this->assertSame(
            array('wp-content/uploads/keep.txt'),
            array_column($this->sort_entries_by_path($response->get_data()['entries']), 'path')
        );

        $stats_body = wp_json_encode(array(
            'paths' => array(
                'wp-content/uploads/keep.txt',
                'wp-content/cache/generated.txt',
                'wp-content/debug.log',
            ),
            'exclude_patterns' => array(),
        ));
        $stats = new WP_REST_Request();
        $stats->set_method('POST');
        $this->sign_request($stats, 'POST', '/wp-json/stack2/v1/backups/job_empty_patterns/files/stats', $stats_body);
        $stats->set_param('job_id', 'job_empty_patterns');

        $stats_response = $api->stat_files($stats);
        $this->assertSame(200, $stats_response->get_status());
        $this->assertSame(array('wp-content/uploads/keep.txt'), array_column($stats_response->get_data()['stats'], 'path'));
        $this->assertSame(
            array('wp-content/cache/generated.txt', 'wp-content/debug.log'),
            $stats_response->get_data()['missing']
        );
    }

    public function test_disable_exclusions_on_scan_and_stats_lifts_path_and_logs(): void
    {
        $this->create_tree(array(
            'wp-content/uploads/keep.txt' => 'keep-me',
            'wp-content/cache/generated.txt' => 'was-excluded',
            'error_log' => 'root-php-log',
            'wp-content/debug.log' => 'wp-debug',
            'wp-content/uploads/foo.log' => 'generic-log',
        ));

        $manager = $this->manager();
        $manager->prepare_backup('b10', true, false, gmdate('c'), 'job_disable');
        $api = $this->api($manager);

        $body = wp_json_encode(array(
            'disable_exclusions' => true,
            'exclude_patterns' => array('/wp-content/cache/'),
        ));
        $scan = new WP_REST_Request();
        $scan->set_method('POST');
        $this->sign_request($scan, 'POST', '/wp-json/stack2/v1/backups/job_disable/files/scan', $body);
        $scan->set_param('job_id', 'job_disable');
        $scan->set_param('limit', 50);

        $scan_response = $api->scan_files($scan);
        $this->assertSame(200, $scan_response->get_status());
        $this->assertSame(
            array(
                'error_log',
                'wp-content/cache/generated.txt',
                'wp-content/debug.log',
                'wp-content/uploads/foo.log',
                'wp-content/uploads/keep.txt',
            ),
            array_column($this->sort_entries_by_path($scan_response->get_data()['entries']), 'path')
        );

        $stats_body = wp_json_encode(array(
            'paths' => array(
                'wp-content/cache/generated.txt',
                'error_log',
                'wp-content/debug.log',
                'wp-content/uploads/foo.log',
            ),
            'include_sha256' => true,
            'disable_exclusions' => true,
        ));
        $stats = new WP_REST_Request();
        $stats->set_method('POST');
        $this->sign_request($stats, 'POST', '/wp-json/stack2/v1/backups/job_disable/files/stats', $stats_body);
        $stats->set_param('job_id', 'job_disable');

        $stats_response = $api->stat_files($stats);
        $this->assertSame(200, $stats_response->get_status());
        $this->assertSame(
            array(
                'wp-content/cache/generated.txt',
                'error_log',
                'wp-content/debug.log',
                'wp-content/uploads/foo.log',
            ),
            array_column($stats_response->get_data()['stats'], 'path')
        );
        $this->assertSame(array(), $stats_response->get_data()['missing']);
    }

    public function test_excluded_list_pages_complete_catalog_with_hmac(): void
    {
        $files = array(
            'wp-content/uploads/keep.txt' => 'keep-me',
            'wp-content/cache/a.txt' => 'cache-a',
            'wp-content/cache/b.txt' => 'cache-b',
            'wp-content/cache/c.txt' => 'cache-c',
            'wp-content/debug.log' => 'wp-debug',
            'error_log' => 'root-php-log',
        );
        $this->create_tree($files);

        $manager = $this->manager();
        $manager->prepare_backup('b11', true, false, gmdate('c'), 'job_excluded');
        $api = $this->api($manager);

        $collected = array();
        $cursor = '';
        for ($i = 0; $i < 20; $i++) {
            $body = wp_json_encode(array(
                'cursor' => $cursor,
                'limit' => 2,
            ));
            $request = new WP_REST_Request();
            $request->set_method('POST');
            $this->sign_request($request, 'POST', '/wp-json/stack2/v1/backups/job_excluded/files/excluded', $body);
            $request->set_param('job_id', 'job_excluded');

            $response = $api->list_excluded_files($request);
            $this->assertSame(200, $response->get_status());
            $payload = $response->get_data();
            $this->assertTrue($payload['success']);
            $this->assertSame('job_excluded', $payload['job_id']);
            foreach ($payload['entries'] as $entry) {
                $this->assertArrayNotHasKey('sha256', $entry);
                $this->assertArrayHasKey('matched_pattern', $entry);
                $collected[] = $entry;
            }

            if (empty($payload['has_more'])) {
                $this->assertNull($payload['next_cursor']);
                break;
            }

            $cursor = (string) $payload['next_cursor'];
        }

        $by_path = array();
        foreach ($this->sort_entries_by_path($collected) as $entry) {
            $by_path[$entry['path']] = $entry['matched_pattern'];
        }
        $this->assertSame(
            array(
                'error_log' => 'error_log',
                'wp-content/cache/a.txt' => '/wp-content/cache/',
                'wp-content/cache/b.txt' => '/wp-content/cache/',
                'wp-content/cache/c.txt' => '/wp-content/cache/',
                'wp-content/debug.log' => 'debug.log',
            ),
            $by_path
        );
    }

    public function test_excluded_list_disable_exclusions_returns_empty_catalog(): void
    {
        $this->create_tree(array(
            'wp-content/cache/generated.txt' => 'cache',
            'wp-content/debug.log' => 'wp-debug',
        ));

        $manager = $this->manager();
        $manager->prepare_backup('b12', true, false, gmdate('c'), 'job_excluded_off');
        $api = $this->api($manager);

        $body = wp_json_encode(array('disable_exclusions' => true));
        $request = new WP_REST_Request();
        $request->set_method('POST');
        $this->sign_request($request, 'POST', '/wp-json/stack2/v1/backups/job_excluded_off/files/excluded', $body);
        $request->set_param('job_id', 'job_excluded_off');
        $request->set_param('limit', 50);

        $response = $api->list_excluded_files($request);
        $this->assertSame(200, $response->get_status());
        $data = $response->get_data();
        $this->assertTrue($data['success']);
        $this->assertSame(array(), $data['entries']);
        $this->assertFalse($data['has_more']);
        $this->assertNull($data['next_cursor']);
    }

    public function test_excluded_list_rejects_bad_hmac_and_unknown_job(): void
    {
        $manager = $this->manager();
        $manager->prepare_backup('b13', true, false, gmdate('c'), 'job_excluded_auth');
        $api = $this->api($manager);

        $bad = new WP_REST_Request();
        $bad->set_header('x-stack2-site-id', 'site_test');
        $bad->set_header('x-stack2-timestamp', (string) time());
        $bad->set_header('x-stack2-signature', str_repeat('d', 64));
        $bad->set_route('/wp-json/stack2/v1/backups/job_excluded_auth/files/excluded');
        $bad->set_param('job_id', 'job_excluded_auth');

        $denied = $api->list_excluded_files($bad);
        $this->assertSame(401, $denied->get_status());
        $this->assertFalse($denied->get_data()['success']);

        $missing = new WP_REST_Request();
        $this->sign_request($missing, 'GET', '/wp-json/stack2/v1/backups/missing_job/files/excluded', '');
        $missing->set_param('job_id', 'missing_job');

        $response = $api->list_excluded_files($missing);
        $this->assertSame(404, $response->get_status());
        $this->assertFalse($response->get_data()['success']);
    }

    public function test_initiate_echoes_disable_exclusions_and_excluded_limits(): void
    {
        $api = $this->api($this->manager());
        $body = wp_json_encode(array(
            'job_id' => 'backup_disable_init',
            'include_files' => true,
            'include_database' => false,
            'disable_exclusions' => true,
        ));

        $request = new WP_REST_Request();
        $this->sign_request($request, 'POST', '/wp-json/stack2/v1/backups/initiate', $body);

        $response = $api->initiate_backup($request);
        $this->assertSame(200, $response->get_status());
        $data = $response->get_data();
        $this->assertTrue($data['disable_exclusions']);
        $this->assertSame(500, $data['excluded']['default_limit']);
        $this->assertSame(2000, $data['excluded']['max_limit']);
    }
}
