<?php

require_once __DIR__ . '/BackupTestCase.php';

class BackupManifestSourcePathsTest extends BackupTestCase
{
    public function test_live_manifest_records_abspath_and_uploads_not_default_wp_content(): void
    {
        $manifest = (new Stack2_Backup_Manifest())->build_manifest(
            '550e8400-e29b-41d4-a716-446655440000',
            'job_source_paths',
            true,
            false,
            array(),
            array(),
            array(
                'backup_started_at' => '2026-09-11T00:00:00Z',
                'manifest_mode' => 'agent',
                'manifest_complete' => true,
            )
        );

        $abspath = trailingslashit(wp_normalize_path(ABSPATH));
        $uploads = trailingslashit(wp_normalize_path(trailingslashit(WP_CONTENT_DIR) . 'uploads'));
        $default_content = trailingslashit(wp_normalize_path(trailingslashit(ABSPATH) . 'wp-content'));

        $this->assertSame(array($abspath, $uploads), $manifest['source_paths']);
        $this->assertNotContains($default_content, $manifest['source_paths']);
        $this->assertSame('', $manifest['upload_path']);
        $this->assertSame('', $manifest['upload_url_path']);
        $this->assertSame(WP_CONTENT_DIR, $manifest['wp_content_path']);
        $this->assertSame(trailingslashit(WP_CONTENT_DIR) . 'uploads', $manifest['wp_uploads_path']);
    }

    public function test_collect_source_paths_matches_live_php_constants(): void
    {
        $this->assertSame(
            (new Stack2_Backup_Manifest())->collect_source_paths(),
            (new Stack2_Backup_Manifest())->build_source_paths(
                ABSPATH,
                WP_CONTENT_DIR,
                trailingslashit(WP_CONTENT_DIR) . 'uploads',
                ''
            )
        );
    }

    public function test_omits_default_wp_content_and_keeps_trailing_slash(): void
    {
        $paths = (new Stack2_Backup_Manifest())->build_source_paths(
            '/var/www/html/',
            '/var/www/html/wp-content',
            '/var/www/html/wp-content/uploads',
            ''
        );

        $this->assertSame(
            array(
                '/var/www/html/',
                '/var/www/html/wp-content/uploads/',
            ),
            $paths
        );
        $this->assertSame($paths, array_values(array_unique($paths)));
        foreach ($paths as $path) {
            $this->assertStringEndsWith('/', $path);
        }
    }

    public function test_includes_custom_wp_content_dir(): void
    {
        $paths = (new Stack2_Backup_Manifest())->build_source_paths(
            '/var/www/html/',
            '/srv/content',
            '/srv/content/uploads',
            ''
        );

        $this->assertSame(
            array(
                '/var/www/html/',
                '/srv/content/',
                '/srv/content/uploads/',
            ),
            $paths
        );
    }

    public function test_relative_upload_path_resolves_against_abspath_and_dedupes_basedir(): void
    {
        $paths = (new Stack2_Backup_Manifest())->build_source_paths(
            '/var/www/html/',
            '/var/www/html/wp-content/',
            '/var/www/html/wp-content/uploads/',
            'wp-content/uploads'
        );

        $this->assertSame(
            array(
                '/var/www/html/',
                '/var/www/html/wp-content/uploads/',
            ),
            $paths
        );
    }

    public function test_non_empty_absolute_upload_path_is_recorded(): void
    {
        $paths = (new Stack2_Backup_Manifest())->build_source_paths(
            '/var/www/html/',
            '/var/www/html/wp-content',
            '/var/www/html/wp-content/uploads',
            '/mnt/uploads'
        );

        $this->assertSame(
            array(
                '/var/www/html/',
                '/var/www/html/wp-content/uploads/',
                '/mnt/uploads/',
            ),
            $paths
        );
    }

    public function test_skips_empty_and_root_only_paths(): void
    {
        $paths = (new Stack2_Backup_Manifest())->build_source_paths('', '', '', '');
        $this->assertSame(array(), $paths);

        $paths = (new Stack2_Backup_Manifest())->build_source_paths('/', '/', '/', '/');
        $this->assertSame(array(), $paths);
    }

    public function test_upload_option_notes_are_trimmed_siblings(): void
    {
        $GLOBALS['stack2_options']['upload_path'] = '  wp-content/uploads  ';
        $GLOBALS['stack2_options']['upload_url_path'] = ' https://cdn.example.com/files ';

        try {
            $manifest = (new Stack2_Backup_Manifest())->build_manifest(
                'id',
                'job',
                false,
                false,
                array()
            );

            $this->assertSame('wp-content/uploads', $manifest['upload_path']);
            $this->assertSame('https://cdn.example.com/files', $manifest['upload_url_path']);
            $this->assertContains(
                trailingslashit(wp_normalize_path(ABSPATH)) . 'wp-content/uploads/',
                $manifest['source_paths']
            );
        } finally {
            unset($GLOBALS['stack2_options']['upload_path'], $GLOBALS['stack2_options']['upload_url_path']);
        }
    }

    public function test_realpath_variant_is_recorded_when_different(): void
    {
        $base = sys_get_temp_dir() . '/stack2-srcpaths-' . uniqid('', true);
        $home = $base . '/home/site';
        $home2 = $base . '/home2/site';
        wp_mkdir_p($home);
        wp_mkdir_p(dirname($home2));

        if (!@symlink($home, $home2)) {
            $this->delete_tree_for_test($base);
            $this->markTestSkipped('Unable to create symlink for realpath variant coverage.');
        }

        try {
            $paths = (new Stack2_Backup_Manifest())->build_source_paths($home2 . '/', '', '', '');
            $this->assertContains(trailingslashit(wp_normalize_path($home2)), $paths);
            $this->assertContains(trailingslashit(wp_normalize_path((string) realpath($home))), $paths);
            $this->assertGreaterThanOrEqual(2, count($paths));
        } finally {
            @unlink($home2);
            $this->delete_tree_for_test($base);
        }
    }

    private function delete_tree_for_test(string $path): void
    {
        if (!file_exists($path) && !is_link($path)) {
            return;
        }

        if (is_file($path) || is_link($path)) {
            @unlink($path);
            return;
        }

        $items = scandir($path);
        if (!is_array($items)) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $this->delete_tree_for_test($path . '/' . $item);
        }

        @rmdir($path);
    }
}
