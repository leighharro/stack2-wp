<?php

require_once __DIR__ . '/BackupTestCase.php';

class BackupApiPagedManifestTest extends BackupTestCase
{
    public function test_initiate_returns_small_envelope_without_file_list(): void
    {
        for ($i = 0; $i < 50; $i++) {
            $this->write_file('wp-content/uploads/n-' . $i . '.txt', str_repeat('x', 100));
        }

        $api = $this->api($this->manager());
        $body = wp_json_encode(array(
            'backup_id' => '550e8400-e29b-41d4-a716-446655440000',
            'job_id' => 'backup_paged_init',
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
        $this->assertSame('paged', $data['manifest_mode']);
        $this->assertFalse($data['manifest_complete']);
        $this->assertSame(Stack2_Backup_Manifest_Store::STATUS_PENDING, $data['manifest_status']);
        $this->assertSame(1000, $data['files_page_size']);
        $this->assertSame('backup_paged_init', $data['job_id']);
        $this->assertSame(array(), $data['manifest']['files']);
        $this->assertSame('paged', $data['manifest']['manifest_mode']);
        $this->assertFalse($data['manifest']['manifest_complete']);
        $this->assertSame(0, $data['manifest']['estimated_files_count']);
        $this->assertSame(array('wp_options', 'wp_posts'), $data['manifest']['tables']);
        $this->assertArrayHasKey('wordpress_version', $data['manifest']);
        $this->assertArrayHasKey('php_version', $data['manifest']);
        $this->assertArrayHasKey('site_url', $data['manifest']);
        $this->assertArrayHasKey('database', $data['manifest']);
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
        $this->assertTrue($data['manifest_complete']);
        $this->assertSame(Stack2_Backup_Manifest_Store::STATUS_READY, $data['manifest_status']);
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

    public function test_manifest_hmac_rejection(): void
    {
        $manager = $this->manager();
        $manager->prepare_backup('b1', true, false, gmdate('c'), 'job_hmac');
        $api = $this->api($manager);

        $request = new WP_REST_Request();
        $request->set_header('x-stack2-site-id', 'site_test');
        $request->set_header('x-stack2-timestamp', (string) time());
        $request->set_header('x-stack2-signature', str_repeat('b', 64));
        $request->set_route('/wp-json/stack2/v1/backups/job_hmac/manifest');
        $request->set_param('job_id', 'job_hmac');
        $request->set_param('cursor', '');
        $request->set_param('limit', 10);

        $response = $api->get_manifest($request);
        $this->assertSame(401, $response->get_status());
        $this->assertFalse($response->get_data()['success']);
    }

    public function test_manifest_pages_all_entries_with_sha256(): void
    {
        $files = array(
            'wp-content/uploads/one.txt' => 'one-body',
            'wp-content/uploads/two.txt' => 'two-body',
            'wp-content/plugins/demo/main.php' => '<?php',
        );
        $this->create_tree($files);

        $manager = $this->manager();
        $prepared = $manager->prepare_backup('b2', true, false, gmdate('c'), 'job_api_pages');
        $api = $this->api($manager);

        $collected = array();
        $cursor = '';
        for ($i = 0; $i < 20; $i++) {
            $request = new WP_REST_Request();
            $path = '/wp-json/stack2/v1/backups/job_api_pages/manifest';
            $this->sign_request($request, 'GET', $path, '');
            $request->set_param('job_id', $prepared['job_id']);
            $request->set_param('cursor', $cursor);
            $request->set_param('limit', 2);

            $response = $api->get_manifest($request);
            if ($response->get_status() === 202) {
                $payload = $response->get_data();
                $this->assertNull($payload['next_cursor']);
                $this->assertTrue($payload['has_more']);
                $this->assertFalse($payload['manifest_complete']);
                $this->assertSame(array(), $payload['files']);
                continue;
            }

            $this->assertSame(200, $response->get_status());
            $payload = $response->get_data();
            $this->assertSame('paged', $payload['manifest_mode']);
            foreach ($payload['files'] as $file) {
                $collected[] = $file;
            }

            if (empty($payload['has_more'])) {
                $this->assertTrue($payload['manifest_complete']);
                $this->assertNull($payload['next_cursor']);
                break;
            }

            $cursor = (string) $payload['next_cursor'];
        }

        $this->assertSame($this->expected_files($files), $collected);
        $this->assertSame($prepared['backup_id'], $api->get_manifest($this->signed_manifest_request($prepared['job_id']))->get_data()['backup_id']);
    }

    public function test_manifest_singular_alias_and_unknown_job(): void
    {
        $api = $this->api($this->manager());
        $request = new WP_REST_Request();
        $this->sign_request($request, 'GET', '/wp-json/stack2/v1/backup/missing_job/manifest', '');
        $request->set_param('job_id', 'missing_job');
        $request->set_param('cursor', '');
        $request->set_param('limit', 10);

        $response = $api->get_manifest($request);
        $this->assertSame(404, $response->get_status());
        $this->assertFalse($response->get_data()['success']);
    }

    public function test_alternate_path_normalization_accepts_singular_signature(): void
    {
        $manager = $this->manager();
        $manager->prepare_backup('b3', false, true, gmdate('c'), 'job_alias');
        $api = $this->api($manager);

        $request = new WP_REST_Request();
        $this->sign_request($request, 'GET', '/wp-json/stack2/v1/backup/job_alias/manifest', '');
        $request->set_route('/wp-json/stack2/v1/backups/job_alias/manifest');
        $request->set_param('job_id', 'job_alias');
        $request->set_param('limit', 10);

        $response = $api->get_manifest($request);
        $this->assertSame(200, $response->get_status());
        $this->assertTrue($response->get_data()['success']);
        $this->assertTrue($response->get_data()['manifest_complete']);
    }

    private function signed_manifest_request(string $job_id): WP_REST_Request
    {
        $request = new WP_REST_Request();
        $this->sign_request($request, 'GET', '/wp-json/stack2/v1/backups/' . $job_id . '/manifest', '');
        $request->set_param('job_id', $job_id);
        $request->set_param('limit', 50);
        return $request;
    }
}
