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

    public function test_theme_and_plugin_cache_code_folders_are_not_excluded(): void
    {
        $root = trailingslashit($this->wp_root);
        $files = array(
            'wp-content/themes/Divi/core/components/cache/Directory.php' => '<?php class ET_Core_Cache_Directory {}',
            'wp-content/themes/Divi/core/components/Cache.php' => '<?php class ET_Core_Cache {}',
            'wp-content/themes/Divi/et-cache/keep.txt' => 'divi-et-cache',
            'wp-content/plugins/sample/Cache/Store.php' => '<?php class Sample_Cache_Store {}',
            'wp-content/cache/object/x' => 'generated-object-cache',
            'wp-content/uploads/cache/tmp.bin' => 'generated-uploads-cache',
            'wp-content/uploads/keep.txt' => 'keep-me',
        );
        $this->create_tree($files);

        $compressor = $this->compressor();
        $this->assertFalse($compressor->is_excluded($root . 'wp-content/themes/Divi/core/components/cache/Directory.php', false));
        $this->assertFalse($compressor->is_excluded($root . 'wp-content/themes/Divi/core/components/cache', true));
        $this->assertFalse($compressor->is_excluded($root . 'wp-content/themes/Divi/core/components/Cache.php', false));
        $this->assertFalse($compressor->is_excluded($root . 'wp-content/themes/Divi/et-cache', true));
        $this->assertFalse($compressor->is_excluded($root . 'wp-content/plugins/sample/Cache/Store.php', false));
        $this->assertTrue($compressor->is_excluded($root . 'wp-content/cache/object/x', false));
        $this->assertTrue($compressor->is_excluded($root . 'wp-content/cache', true));
        $this->assertTrue($compressor->is_excluded($root . 'wp-content/uploads/cache/tmp.bin', false));

        $this->assertNotNull($compressor->metadata_for_relative_path('wp-content/themes/Divi/core/components/cache/Directory.php'));
        $this->assertNotNull($compressor->metadata_for_relative_path('wp-content/themes/Divi/core/components/Cache.php'));
        $this->assertNull($compressor->metadata_for_relative_path('wp-content/cache/object/x'));
        $this->assertNull($compressor->metadata_for_relative_path('wp-content/uploads/cache/tmp.bin'));

        $entries = $this->collect_all_scan_pages($this->scanner(), 10);
        $this->assertSame(
            $this->expected_scan_entries($files),
            $this->sort_entries_by_path($entries)
        );
        $this->assertSame(
            array(
                'wp-content/plugins/sample/Cache/Store.php',
                'wp-content/themes/Divi/core/components/Cache.php',
                'wp-content/themes/Divi/core/components/cache/Directory.php',
                'wp-content/themes/Divi/et-cache/keep.txt',
                'wp-content/uploads/keep.txt',
            ),
            array_column($this->sort_entries_by_path($entries), 'path')
        );

        $stats = $this->scanner()->stats(array(
            'wp-content/themes/Divi/core/components/cache/Directory.php',
            'wp-content/themes/Divi/core/components/Cache.php',
            'wp-content/cache/object/x',
            'wp-content/uploads/cache/tmp.bin',
        ), true);
        $this->assertSame(
            array(
                'wp-content/themes/Divi/core/components/cache/Directory.php',
                'wp-content/themes/Divi/core/components/Cache.php',
            ),
            array_column($stats['stats'], 'path')
        );
        $this->assertSame(
            array(
                'wp-content/cache/object/x',
                'wp-content/uploads/cache/tmp.bin',
            ),
            $stats['missing']
        );
        $this->assertSame(array(), $stats['failed']);
    }

    public function test_log_basenames_are_excluded_and_bak_files_are_not(): void
    {
        $root = trailingslashit($this->wp_root);
        $files = array(
            'error_log' => 'root-php-log',
            'wp-content/error_log' => 'nested-php-log',
            'wp-content/php_errorlog' => 'cpanel-php-log',
            'wp-content/debug.log' => 'wp-debug-log',
            'wp-content/uploads/foo.log' => 'generic-log',
            'wp-content/uploads/error.log' => 'error-dot-log',
            'wp-content/uploads/DEBUG.LOG' => 'uppercase-debug',
            'wp-content/uploads/keep.txt' => 'keep-me',
            'wp-content/uploads/error_log.bak' => 'not-the-php-log',
            'wp-content/uploads/something.log.bak' => 'rotated-backup',
        );
        $this->create_tree($files);

        $compressor = $this->compressor();
        $this->assertTrue($compressor->is_excluded($root . 'error_log', false));
        $this->assertTrue($compressor->is_excluded($root . 'wp-content/error_log', false));
        $this->assertTrue($compressor->is_excluded($root . 'wp-content/php_errorlog', false));
        $this->assertTrue($compressor->is_excluded($root . 'wp-content/debug.log', false));
        $this->assertTrue($compressor->is_excluded($root . 'wp-content/uploads/foo.log', false));
        $this->assertTrue($compressor->is_excluded($root . 'wp-content/uploads/error.log', false));
        $this->assertTrue($compressor->is_excluded($root . 'wp-content/uploads/DEBUG.LOG', false));
        $this->assertTrue($compressor->is_excluded($root . 'wp-content/PHP_ERRORLOG', false));
        $this->assertFalse($compressor->is_excluded($root . 'wp-content/uploads/keep.txt', false));
        $this->assertFalse($compressor->is_excluded($root . 'wp-content/uploads/error_log.bak', false));
        $this->assertFalse($compressor->is_excluded($root . 'wp-content/uploads/something.log.bak', false));

        $this->assertNull($compressor->metadata_for_relative_path('error_log'));
        $this->assertNull($compressor->metadata_for_relative_path('wp-content/php_errorlog'));
        $this->assertNull($compressor->metadata_for_relative_path('wp-content/debug.log'));
        $this->assertNull($compressor->metadata_for_relative_path('wp-content/uploads/foo.log'));
        $this->assertNotNull($compressor->metadata_for_relative_path('wp-content/uploads/keep.txt'));
        $this->assertNotNull($compressor->metadata_for_relative_path('wp-content/uploads/error_log.bak'));
        $this->assertNotNull($compressor->metadata_for_relative_path('wp-content/uploads/something.log.bak'));

        $entries = $this->collect_all_scan_pages($this->scanner(), 10);
        $this->assertSame(
            $this->expected_scan_entries($files),
            $this->sort_entries_by_path($entries)
        );
        $this->assertSame(
            array(
                'wp-content/uploads/error_log.bak',
                'wp-content/uploads/keep.txt',
                'wp-content/uploads/something.log.bak',
            ),
            array_column($this->sort_entries_by_path($entries), 'path')
        );

        $stats = $this->scanner()->stats(array(
            'error_log',
            'wp-content/php_errorlog',
            'wp-content/debug.log',
            'wp-content/uploads/foo.log',
            'wp-content/uploads/keep.txt',
            'wp-content/uploads/error_log.bak',
            'wp-content/uploads/something.log.bak',
        ), true);
        $this->assertSame(
            array(
                'wp-content/uploads/keep.txt',
                'wp-content/uploads/error_log.bak',
                'wp-content/uploads/something.log.bak',
            ),
            array_column($stats['stats'], 'path')
        );
        $this->assertSame(
            array(
                'error_log',
                'wp-content/php_errorlog',
                'wp-content/debug.log',
                'wp-content/uploads/foo.log',
            ),
            $stats['missing']
        );
        $this->assertSame(array(), $stats['failed']);
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
        $this->assertSame(array('wp-content/uploads/gone.txt', 'wp-content/cache/hidden.txt'), $result['missing']);
        $this->assertCount(1, $result['failed']);
        $this->assertSame('../outside.txt', $result['failed'][0]['path']);
        $this->assertSame('Invalid path.', $result['failed'][0]['error']);
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

    public function test_platform_exclude_patterns_replace_local_defaults(): void
    {
        $root = trailingslashit($this->wp_root);
        $files = array(
            'wp-content/uploads/keep.txt' => 'keep-me',
            'wp-content/custom-skip/secret.txt' => 'platform-only',
            'wp-content/cache/generated.txt' => 'would-be-local-default',
        );
        $this->create_tree($files);

        $platform = array('/custom-skip/');
        $compressor = $this->compressor();
        $this->assertTrue($compressor->is_excluded($root . 'wp-content/custom-skip/secret.txt', false, $platform));
        $this->assertFalse($compressor->is_excluded($root . 'wp-content/cache/generated.txt', false, $platform));
        $this->assertFalse($compressor->is_excluded($root . 'wp-content/cache', true, $platform));

        $entries = $this->collect_all_scan_pages($this->scanner(), 10, false, false, $platform);
        $this->assertSame(
            array(
                'wp-content/cache/generated.txt',
                'wp-content/uploads/keep.txt',
            ),
            array_column($this->sort_entries_by_path($entries), 'path')
        );

        $stats = $this->scanner()->stats(array(
            'wp-content/uploads/keep.txt',
            'wp-content/custom-skip/secret.txt',
            'wp-content/cache/generated.txt',
        ), true, $platform);
        $this->assertSame(
            array(
                'wp-content/uploads/keep.txt',
                'wp-content/cache/generated.txt',
            ),
            array_column($stats['stats'], 'path')
        );
        $this->assertSame(array('wp-content/custom-skip/secret.txt'), $stats['missing']);
    }

    public function test_platform_post_114_defaults_include_divi_cache_and_exclude_wp_content_cache(): void
    {
        $this->assertNotContains('/cache/', Stack2_Backup_Compressor::EXCLUSION_PATTERNS);

        $files = array(
            'wp-content/themes/Divi/core/components/cache/Directory.php' => '<?php class ET_Core_Cache_Directory {}',
            'wp-content/cache/object/x' => 'generated-object-cache',
            'wp-content/uploads/keep.txt' => 'keep-me',
        );
        $this->create_tree($files);

        $platform = Stack2_Backup_Compressor::EXCLUSION_PATTERNS;
        $entries = $this->collect_all_scan_pages($this->scanner(), 10, false, false, $platform);
        $this->assertSame(
            array(
                'wp-content/themes/Divi/core/components/cache/Directory.php',
                'wp-content/uploads/keep.txt',
            ),
            array_column($this->sort_entries_by_path($entries), 'path')
        );

        $stats = $this->scanner()->stats(array(
            'wp-content/themes/Divi/core/components/cache/Directory.php',
            'wp-content/cache/object/x',
        ), true, $platform);
        $this->assertSame(
            array('wp-content/themes/Divi/core/components/cache/Directory.php'),
            array_column($stats['stats'], 'path')
        );
        $this->assertSame(array('wp-content/cache/object/x'), $stats['missing']);
    }

    public function test_omitted_exclude_patterns_use_local_defaults(): void
    {
        $files = array(
            'wp-content/themes/Divi/core/components/cache/Directory.php' => '<?php class ET_Core_Cache_Directory {}',
            'wp-content/cache/object/x' => 'generated-object-cache',
            'wp-content/uploads/keep.txt' => 'keep-me',
        );
        $this->create_tree($files);

        $entries = $this->collect_all_scan_pages($this->scanner(), 10);
        $this->assertSame(
            array(
                'wp-content/themes/Divi/core/components/cache/Directory.php',
                'wp-content/uploads/keep.txt',
            ),
            array_column($this->sort_entries_by_path($entries), 'path')
        );

        $empty = $this->scanner()->scan('', 20, false, false, array());
        $this->assertSame(
            array_column($this->sort_entries_by_path($entries), 'path'),
            array_column($this->sort_entries_by_path($empty['entries']), 'path')
        );
    }
}
