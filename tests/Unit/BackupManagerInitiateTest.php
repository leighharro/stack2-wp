<?php

require_once __DIR__ . '/BackupTestCase.php';

class BackupManagerInitiateTest extends BackupTestCase
{
    public function test_prepare_backup_is_stateless_and_agent_mode(): void
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
        $this->assertSame('agent', $prepared['manifest_mode']);
        $this->assertTrue(is_dir($prepared['temp_dir']));
        $this->assertSame(500, $prepared['inventory_limits']['scan']['default_limit']);
        $this->assertSame(2000, $prepared['inventory_limits']['scan']['max_limit']);
        $this->assertSame(200, $prepared['inventory_limits']['stats']['max_batch']);

        $manifest = $manager->build_initiate_manifest($prepared);
        $this->assertSame(array(), $manifest['files']);
        $this->assertSame(0, $manifest['estimated_files_count']);
        $this->assertSame('agent', $manifest['manifest_mode']);
        $this->assertTrue($manifest['manifest_complete']);

        $job_dir = trailingslashit($this->backup_dir) . 'job_prepare';
        $this->assertDirectoryExists($job_dir);
        $this->assertFileDoesNotExist($job_dir . '/files.ndjson');
        $this->assertFileDoesNotExist($job_dir . '/paths.ndjson');
        $this->assertFileDoesNotExist($job_dir . '/manifest-state.json');
        $this->assertSame(array(), $GLOBALS['stack2_cron']);
        $this->assertSame(array(), $GLOBALS['stack2_transients']);
    }

    public function test_include_files_false_still_creates_job_dir(): void
    {
        $manager = $this->manager();
        $prepared = $manager->prepare_backup('db-only', false, true, gmdate('c'), 'job_db');
        $this->assertSame('agent', $prepared['manifest_mode']);
        $this->assertDirectoryExists($prepared['temp_dir']);
        $this->assertSame(array(), $GLOBALS['stack2_cron']);
    }

    public function test_scan_requires_initiated_job(): void
    {
        $manager = $this->manager();
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Backup job not found.');
        $manager->scan_files('missing_job', '', 10, false, false);
    }
}
