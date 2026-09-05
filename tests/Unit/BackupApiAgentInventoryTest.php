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
}
