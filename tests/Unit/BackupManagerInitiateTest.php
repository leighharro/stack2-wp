<?php

require_once __DIR__ . '/BackupTestCase.php';

class BackupManagerInitiateTest extends BackupTestCase
{
    public function test_prepare_backup_does_not_hash_or_list_files(): void
    {
        $this->write_file('wp-content/uploads/big.bin', str_repeat('z', 2048));

        $manager = $this->manager();
        $prepared = $manager->prepare_backup(
            '550e8400-e29b-41d4-a716-446655440000',
            true,
            false,
            '2026-09-04T12:00:00Z',
            'job_prepare'
        );

        $this->assertSame('job_prepare', $prepared['job_id']);
        $this->assertFalse($prepared['manifest_complete']);
        $this->assertSame(Stack2_Backup_Manifest_Store::STATUS_PENDING, $prepared['manifest_status']);

        $manifest = $manager->build_initiate_manifest($prepared);
        $this->assertSame(array(), $manifest['files']);
        $this->assertSame(0, $manifest['estimated_files_count']);
        $this->assertSame('paged', $manifest['manifest_mode']);
        $this->assertFalse($manifest['manifest_complete']);

        $files_ndjson = trailingslashit($this->backup_dir) . 'job_prepare/files.ndjson';
        $this->assertFileExists($files_ndjson);
        $this->assertSame('', file_get_contents($files_ndjson));

        $this->assertNotEmpty($GLOBALS['stack2_cron']);
        $this->assertSame(Stack2_Backup_Manager::CRON_HOOK_MANIFEST, $GLOBALS['stack2_cron'][0]['hook']);
    }

    public function test_include_files_false_marks_manifest_complete(): void
    {
        $manager = $this->manager();
        $prepared = $manager->prepare_backup('db-only', false, true, gmdate('c'), 'job_db');
        $this->assertTrue($prepared['manifest_complete']);
        $this->assertSame(Stack2_Backup_Manifest_Store::STATUS_READY, $prepared['manifest_status']);

        $page = $manager->get_manifest_page('job_db', '', 100);
        $this->assertSame(200, $page['http_status']);
        $this->assertSame(array(), $page['payload']['files']);
        $this->assertTrue($page['payload']['manifest_complete']);
    }

    public function test_continue_manifest_build_finishes_inventory(): void
    {
        $this->write_file('wp-content/uploads/a.txt', 'aaa');
        $this->write_file('wp-content/uploads/b.txt', 'bbb');

        $manager = $this->manager(array(
            'chunk_file_limit' => 1,
            'index_file_limit' => 1,
        ));
        $manager->prepare_backup('b-cont', true, false, gmdate('c'), 'job_cont');

        for ($i = 0; $i < 10; $i++) {
            $manager->continue_manifest_build('job_cont');
            $page = $manager->get_manifest_page('job_cont', '', 50);
            if (!empty($page['payload']['manifest_complete'])) {
                $this->assertCount(2, $page['payload']['files']);
                return;
            }
        }

        $this->fail('Manifest build did not complete across bounded chunks.');
    }
}
