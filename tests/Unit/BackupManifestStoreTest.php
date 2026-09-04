<?php

require_once __DIR__ . '/BackupTestCase.php';

class BackupManifestStoreTest extends BackupTestCase
{
    public function test_paging_returns_every_file_with_sha256(): void
    {
        $files = array(
            'wp-content/uploads/a.txt' => 'alpha',
            'wp-content/uploads/b.txt' => 'bravo',
            'wp-content/plugins/sample/plugin.php' => '<?php echo 1;',
            'wp-content/cache/skip.txt' => 'excluded',
            'wp-content/.stack2-backup/skip.txt' => 'excluded',
        );
        $this->create_tree($files);

        $store = $this->store('job_paging');
        $store->initialize('backup-1', 'job_paging', true, false, gmdate('c'));

        $paged = $this->collect_all_pages($store, 2);
        $expected = $this->expected_files($files);

        $this->assertCount(count($expected), $paged);
        $this->assertSame($expected, $paged);

        foreach ($paged as $entry) {
            $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $entry['sha256']);
            $this->assertSame($entry['sha256'], strtolower($entry['sha256']));
            $this->assertArrayHasKey('path', $entry);
            $this->assertArrayHasKey('size', $entry);
        }
    }

    public function test_cursor_pagination_is_complete_and_stable(): void
    {
        $files = array();
        for ($i = 0; $i < 25; $i++) {
            $files[sprintf('wp-content/uploads/item-%02d.txt', $i)] = 'body-' . $i;
        }
        $this->create_tree($files);

        $store = $this->store('job_cursors');
        $store->initialize('backup-2', 'job_cursors', true, false, gmdate('c'));

        $first = $this->collect_all_pages($store, 7);
        $second = $this->collect_all_pages($store, 7);

        $this->assertSame($first, $second);
        $this->assertCount(25, $first);
        $this->assertSame($this->expected_files($files), $first);
    }

    public function test_empty_site_completes_with_no_files(): void
    {
        $store = $this->store('job_empty');
        $store->initialize('backup-empty', 'job_empty', true, false, gmdate('c'));

        $page = $store->get_page('', 100, true);
        $this->assertSame(200, $page['http_status']);
        $this->assertSame(array(), $page['payload']['files']);
        $this->assertFalse($page['payload']['has_more']);
        $this->assertNull($page['payload']['next_cursor']);
        $this->assertTrue($page['payload']['manifest_complete']);
        $this->assertSame(0, $page['payload']['estimated_files_count']);
        $this->assertSame(Stack2_Backup_Manifest_Store::STATUS_READY, $page['payload']['manifest_status']);
    }

    public function test_include_files_false_skips_walk(): void
    {
        $this->write_file('wp-content/uploads/keep.txt', 'should not be listed');

        $store = $this->store('job_db_only');
        $store->initialize('backup-db', 'job_db_only', false, true, gmdate('c'));

        $page = $store->get_page(null, 50, true);
        $this->assertSame(200, $page['http_status']);
        $this->assertSame(array(), $page['payload']['files']);
        $this->assertTrue($page['payload']['manifest_complete']);
        $this->assertSame(Stack2_Backup_Manifest_Store::STATUS_READY, $page['payload']['manifest_status']);
        $this->assertFalse(is_file(trailingslashit($this->backup_dir) . 'job_db_only/files.ndjson'));
    }

    public function test_incomplete_ndjson_line_is_not_returned_as_success(): void
    {
        $this->write_file('wp-content/uploads/one.txt', 'one');
        $store = $this->store('job_trunc', array(
            'chunk_file_limit' => 1,
            'index_file_limit' => 1,
        ));
        $store->initialize('backup-trunc', 'job_trunc', true, false, gmdate('c'));
        $store->build_chunk();
        $store->build_chunk();

        $files_path = trailingslashit($this->backup_dir) . 'job_trunc/files.ndjson';
        $this->assertFileExists($files_path);
        file_put_contents($files_path, file_get_contents($files_path) . '{"path":"partial","sha256":"abc"');

        $state_path = trailingslashit($this->backup_dir) . 'job_trunc/manifest-state.json';
        $state = json_decode(file_get_contents($state_path), true);
        $state['status'] = Stack2_Backup_Manifest_Store::STATUS_BUILDING;
        $state['index_complete'] = false;
        file_put_contents($state_path, wp_json_encode($state));

        $page = $store->get_page('', 50, false);
        $this->assertSame(202, $page['http_status']);
        $this->assertTrue($page['payload']['success']);
        $this->assertSame(array(), $page['payload']['files']);
        $this->assertFalse($page['payload']['manifest_complete']);
        $this->assertTrue($page['payload']['has_more']);
    }

    public function test_corrupt_complete_line_is_not_success(): void
    {
        $store = $this->store('job_corrupt');
        $store->initialize('backup-corrupt', 'job_corrupt', true, false, gmdate('c'));

        $job_dir = trailingslashit($this->backup_dir) . 'job_corrupt';
        file_put_contents($job_dir . '/files.ndjson', "{\"path\":\"x\",\"sha256\":\"not-a-hash\",\"size\":1}\n");
        file_put_contents($job_dir . '/manifest-state.json', wp_json_encode(array(
            'status' => Stack2_Backup_Manifest_Store::STATUS_READY,
            'include_files' => true,
            'index_complete' => true,
            'paths_count' => 1,
            'last_path' => 'x',
            'hash_offset' => 1,
            'files_written' => 1,
            'error' => null,
            'updated_at' => gmdate('c'),
        )));

        $page = $store->get_page('', 10, false);
        $this->assertSame(500, $page['http_status']);
        $this->assertFalse($page['payload']['success']);
        $this->assertSame(array(), $page['payload']['files']);
        $this->assertFalse($page['payload']['manifest_complete']);
    }

    public function test_invalid_cursor_is_rejected(): void
    {
        $store = $this->store('job_cursor');
        $store->initialize('backup-cursor', 'job_cursor', true, false, gmdate('c'));

        $page = $store->get_page('%%%not-valid%%%', 10, false);
        $this->assertSame(400, $page['http_status']);
        $this->assertFalse($page['payload']['success']);
        $this->assertSame('Invalid manifest cursor.', $page['payload']['error']);
    }

    public function test_limit_is_clamped_to_hard_max(): void
    {
        $this->assertSame(1000, Stack2_Backup_Manifest_Store::normalize_limit(0));
        $this->assertSame(2000, Stack2_Backup_Manifest_Store::normalize_limit(99999));
        $this->assertSame(25, Stack2_Backup_Manifest_Store::normalize_limit(25));
    }

    public function test_building_status_when_chunk_does_not_fill_page(): void
    {
        $this->write_file('wp-content/uploads/a.txt', 'a');
        $this->write_file('wp-content/uploads/b.txt', 'b');
        $this->write_file('wp-content/uploads/c.txt', 'c');

        $store = $this->store('job_building', array(
            'chunk_file_limit' => 1,
            'index_file_limit' => 100,
        ));
        $store->initialize('backup-building', 'job_building', true, false, gmdate('c'));

        $first = $store->get_page('', 10, true);
        $this->assertContains($first['http_status'], array(200, 202));
        if ((int) $first['http_status'] === 202) {
            $this->assertSame(array(), $first['payload']['files']);
            $this->assertSame(Stack2_Backup_Manifest_Store::STATUS_BUILDING, $first['payload']['manifest_status']);
            $this->assertFalse($first['payload']['manifest_complete']);
        }
    }

    public function test_synthetic_large_tree_pages_completely(): void
    {
        $files = array();
        for ($i = 0; $i < 2200; $i++) {
            $relative = sprintf('wp-content/uploads/bulk/file-%04d.txt', $i);
            $files[$relative] = 'payload-' . $i;
            $this->write_file($relative, $files[$relative]);
        }

        $store = $this->store('job_large', array(
            'chunk_file_limit' => 350,
            'index_file_limit' => 800,
            'chunk_seconds' => 20,
        ));
        $store->initialize('backup-large', 'job_large', true, false, gmdate('c'));

        $paged = $this->collect_all_pages($store, 400);
        $this->assertCount(2200, $paged);
        $this->assertSame($this->expected_files($files), $paged);

        $sample = $paged[1234];
        $this->assertSame(hash('sha256', 'payload-1234'), $sample['sha256']);
        $this->assertSame(strlen('payload-1234'), $sample['size']);
    }
}
