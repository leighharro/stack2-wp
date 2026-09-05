<?php

require_once __DIR__ . '/BackupTestCase.php';

class BackupFileScannerTest extends BackupTestCase
{
    public function test_scan_pagination_cursor_resumes_without_duplicates(): void
    {
        $files = array();
        for ($i = 0; $i < 25; $i++) {
            $files[sprintf('wp-content/uploads/item-%02d.txt', $i)] = 'body-' . $i;
        }
        $files['wp-content/plugins/sample/plugin.php'] = '<?php echo 1;';
        $this->create_tree($files);

        $scanner = $this->scanner();
        $first_page = $scanner->scan('', 4, false, false);
        $this->assertCount(4, $first_page['entries']);
        $this->assertTrue($first_page['has_more']);
        $this->assertNotNull($first_page['next_cursor']);

        $stack = $scanner->decode_cursor($first_page['next_cursor']);
        $this->assertNotEmpty($stack);
        $this->assertArrayHasKey('p', $stack[0]);
        $this->assertArrayHasKey('i', $stack[0]);
        $this->assertIsString($stack[0]['p']);
        $this->assertIsInt($stack[0]['i']);

        $rest = array();
        $cursor = $first_page['next_cursor'];
        for ($i = 0; $i < 50; $i++) {
            $page = $scanner->scan($cursor, 4, false, false);
            foreach ($page['entries'] as $entry) {
                $rest[] = $entry;
            }
            if (empty($page['has_more'])) {
                $this->assertNull($page['next_cursor']);
                break;
            }
            $cursor = $page['next_cursor'];
        }

        $combined = array_merge($first_page['entries'], $rest);
        $paths = array_column($combined, 'path');
        $this->assertSame(array_values(array_unique($paths)), $paths);

        $expected = $this->expected_scan_entries($files);
        $this->assertSame($expected, $this->sort_entries_by_path($combined));

        $replay = $this->collect_all_scan_pages($scanner, 7);
        $this->assertSame($expected, $this->sort_entries_by_path($replay));
    }

    public function test_scan_excludes_cache_updraft_and_backup_scratch(): void
    {
        $files = array(
            'wp-content/uploads/keep.txt' => 'keep-me',
            'wp-content/cache/skip.txt' => 'excluded',
            'wp-content/.stack2-backup/skip.txt' => 'excluded',
            'wp-content/updraft/old.zip' => 'excluded',
        );
        $this->create_tree($files);

        $entries = $this->collect_all_scan_pages($this->scanner(), 10);
        $this->assertSame(
            $this->expected_scan_entries($files),
            $this->sort_entries_by_path($entries)
        );
        $this->assertSame(array('wp-content/uploads/keep.txt'), array_column($entries, 'path'));
    }

    public function test_default_scan_omits_sha256(): void
    {
        $this->write_file('wp-content/uploads/plain.txt', 'plain');
        $page = $this->scanner()->scan('', 10, false, false);

        $this->assertCount(1, $page['entries']);
        $this->assertArrayNotHasKey('sha256', $page['entries'][0]);
        $this->assertArrayHasKey('mtime', $page['entries'][0]);
        $this->assertSame(array(), $GLOBALS['stack2_transients']);
    }

    public function test_scan_can_include_sha256(): void
    {
        $files = array('wp-content/uploads/hashed.txt' => 'hash-me');
        $this->create_tree($files);

        $page = $this->scanner()->scan('', 10, true, false);
        $this->assertSame($this->expected_scan_entries($files, true), $this->sort_entries_by_path($page['entries']));
        $this->assertNotEmpty($GLOBALS['stack2_transients']);
    }

    public function test_stats_reports_missing_failed_and_hashed_paths(): void
    {
        $this->write_file('wp-content/uploads/present.txt', 'present-body');
        $this->write_file('wp-content/cache/hidden.txt', 'nope');

        $result = $this->scanner()->stats(array(
            'wp-content/uploads/present.txt',
            'wp-content/uploads/gone.txt',
            'wp-content/cache/hidden.txt',
            '../outside.txt',
        ), true);

        $this->assertCount(1, $result['stats']);
        $this->assertSame('wp-content/uploads/present.txt', $result['stats'][0]['path']);
        $this->assertSame(hash('sha256', 'present-body'), $result['stats'][0]['sha256']);
        $this->assertSame(strlen('present-body'), $result['stats'][0]['size']);
        $this->assertArrayHasKey('mtime', $result['stats'][0]);
        $this->assertSame(array('wp-content/uploads/gone.txt'), $result['missing']);
        $this->assertCount(2, $result['failed']);
        $this->assertSame('wp-content/cache/hidden.txt', $result['failed'][0]['path']);
        $this->assertSame('Path is excluded from backup inventory.', $result['failed'][0]['error']);
        $this->assertSame('../outside.txt', $result['failed'][1]['path']);
        $this->assertSame('Invalid path.', $result['failed'][1]['error']);
    }

    public function test_stats_rejects_over_max_batch(): void
    {
        $paths = array();
        for ($i = 0; $i < 201; $i++) {
            $paths[] = 'wp-content/uploads/f-' . $i . '.txt';
        }

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Too many paths. Maximum is 200.');
        $this->scanner()->stats($paths, true);
    }

    public function test_invalid_cursor_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid scan cursor.');
        $this->scanner()->scan('%%%not-valid%%%', 10, false, false);
    }

    public function test_legacy_paged_index_cursor_is_rejected(): void
    {
        $legacy = rtrim(strtr(base64_encode('{"i":0}'), '+/', '-_'), '=');
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid scan cursor.');
        $this->scanner()->scan($legacy, 10, false, false);
    }

    public function test_limit_is_clamped_to_hard_max(): void
    {
        $this->assertSame(500, Stack2_Backup_File_Scanner::normalize_scan_limit(0));
        $this->assertSame(2000, Stack2_Backup_File_Scanner::normalize_scan_limit(99999));
        $this->assertSame(25, Stack2_Backup_File_Scanner::normalize_scan_limit(25));
    }

    public function test_empty_root_completes_with_no_entries(): void
    {
        $page = $this->scanner()->scan('', 100, false, false);
        $this->assertSame(array(), $page['entries']);
        $this->assertFalse($page['has_more']);
        $this->assertNull($page['next_cursor']);
    }
}
