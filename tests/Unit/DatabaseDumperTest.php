<?php

require_once __DIR__ . '/BackupTestCase.php';

class DatabaseDumperTest extends BackupTestCase
{
    private $previous_wpdb;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previous_wpdb = $GLOBALS['wpdb'] ?? null;
    }

    protected function tearDown(): void
    {
        $GLOBALS['wpdb'] = $this->previous_wpdb instanceof FakeWpdb
            ? $this->previous_wpdb
            : new FakeWpdb();
        parent::tearDown();
    }

    public function test_options_table_dump_excludes_checksum_transients(): void
    {
        $wpdb = $this->options_wpdb(array(
            $this->option_row(1, 'siteurl', 'https://example.com'),
            $this->option_row(2, '_transient_stack2_cksum_aaa', 'deadbeef'),
            $this->option_row(3, '_transient_timeout_stack2_cksum_aaa', '1790022285'),
            $this->option_row(4, '_site_transient_stack2_cksum_bbb', 'cafebabe'),
            $this->option_row(5, '_site_transient_timeout_stack2_cksum_bbb', '1790022285'),
            $this->option_row(6, '_transient_unrelated_cache', 'keep-me'),
            $this->option_row(7, 'blogname', 'Example'),
        ));
        $GLOBALS['wpdb'] = $wpdb;

        $dumper = new Stack2_Database_Dumper($this->logger());
        $dump = $dumper->dump_database_table($this->backup_dir, 'wp_options');
        $sql = $this->gunzip_file($dump['file']);

        $this->assertStringContainsString("CREATE TABLE `wp_options`", $sql);
        $this->assertStringContainsString("'siteurl'", $sql);
        $this->assertStringContainsString("'blogname'", $sql);
        $this->assertStringContainsString("'_transient_unrelated_cache'", $sql);
        $this->assertStringNotContainsString('_transient_stack2_cksum_', $sql);
        $this->assertStringNotContainsString('_transient_timeout_stack2_cksum_', $sql);
        $this->assertStringNotContainsString('_site_transient_stack2_cksum_', $sql);
        $this->assertStringContainsString('SET FOREIGN_KEY_CHECKS=1', $sql);

        $selects = array_filter($wpdb->queries, static function ($query) {
            return strpos($query, 'SELECT * FROM `wp_options`') !== false;
        });
        $this->assertNotEmpty($selects);
        foreach ($selects as $query) {
            $this->assertStringContainsString('NOT LIKE', $query);
        }
    }

    public function test_prefixed_options_table_is_filtered(): void
    {
        $wpdb = $this->options_wpdb(array(
            $this->option_row(1, 'siteurl', 'https://example.com'),
            $this->option_row(2, '_transient_stack2_cksum_dead', 'hash'),
        ), 'mcj_options');
        $GLOBALS['wpdb'] = $wpdb;

        $dumper = new Stack2_Database_Dumper($this->logger());
        $dump = $dumper->dump_database_table($this->backup_dir, 'mcj_options');
        $sql = $this->gunzip_file($dump['file']);

        $this->assertStringContainsString("'siteurl'", $sql);
        $this->assertStringNotContainsString('_transient_stack2_cksum_', $sql);
    }

    public function test_non_options_table_is_not_filtered(): void
    {
        $wpdb = new TableDumpWpdb();
        $wpdb->tables['wp_posts'] = array(
            array(
                'ID' => 1,
                'post_title' => '_transient_stack2_cksum_keep',
            ),
        );
        $wpdb->create_sql['wp_posts'] = "CREATE TABLE `wp_posts` (\n  `ID` bigint NOT NULL,\n  `post_title` varchar(255)\n)";
        $GLOBALS['wpdb'] = $wpdb;

        $dumper = new Stack2_Database_Dumper($this->logger());
        $dump = $dumper->dump_database_table($this->backup_dir, 'wp_posts');
        $sql = $this->gunzip_file($dump['file']);

        $this->assertStringContainsString("'_transient_stack2_cksum_keep'", $sql);
        $selects = array_filter($wpdb->queries, static function ($query) {
            return strpos($query, 'SELECT * FROM `wp_posts`') !== false;
        });
        $this->assertNotEmpty($selects);
        foreach ($selects as $query) {
            $this->assertStringNotContainsString('NOT LIKE', $query);
        }
    }

    public function test_verify_dump_accepts_complete_gzip_and_rejects_truncated(): void
    {
        $GLOBALS['wpdb'] = $this->options_wpdb(array(
            $this->option_row(1, 'siteurl', 'https://example.com'),
        ));

        $dumper = new Stack2_Database_Dumper($this->logger());
        $dump = $dumper->dump_database_table($this->backup_dir, 'wp_options');
        $complete = $dump['file'];
        $this->assertTrue($this->invoke_verify($dumper, $complete));

        $footer_clipped = $this->backup_dir . '/footer-clipped.sql.gz';
        $bytes = file_get_contents($complete);
        $this->assertNotFalse($bytes);
        $this->assertGreaterThan(20, strlen($bytes));
        file_put_contents($footer_clipped, substr($bytes, 0, -4));
        $this->assertFalse($this->invoke_verify($dumper, $footer_clipped));

        $half_cut = $this->backup_dir . '/half-cut.sql.gz';
        file_put_contents($half_cut, substr($bytes, 0, (int) floor(strlen($bytes) / 2)));
        $this->assertFalse($this->invoke_verify($dumper, $half_cut));

        $cached = $dumper->get_table_dump_if_exists($this->backup_dir, 'wp_options');
        $this->assertSame($complete, $cached['file']);

        copy($footer_clipped, $complete);
        $this->assertSame(array(), $dumper->get_table_dump_if_exists($this->backup_dir, 'wp_options'));
        $this->assertFileDoesNotExist($complete);
    }

    public function test_verify_dump_rejects_complete_gzip_of_incomplete_sql(): void
    {
        $incomplete_sql = "-- Stack2 WordPress table backup\n"
            . "-- Table wp_options\n\n"
            . "SET FOREIGN_KEY_CHECKS=0;\n\n"
            . "DROP TABLE IF EXISTS `wp_options`;\n"
            . "CREATE TABLE `wp_options` (\n  `option_id` bigint NOT NULL\n);\n\n"
            . "INSERT INTO `wp_options` (`option_id`) VALUES (1);\n";

        $path = $this->backup_dir . '/incomplete-sql.sql.gz';
        $gz = gzencode($incomplete_sql, 6);
        $this->assertNotFalse($gz);
        file_put_contents($path, $gz);

        $dumper = new Stack2_Database_Dumper($this->logger());
        $this->assertFalse($this->invoke_verify($dumper, $path));
    }

    public function test_aborted_first_stream_discards_truncated_cache(): void
    {
        $rows = array();
        for ($i = 1; $i <= 50; $i++) {
            $rows[] = $this->option_row($i, 'option_' . $i, 'value-' . $i);
        }
        $GLOBALS['wpdb'] = $this->options_wpdb($rows);

        $dumper = new AbortAfterFirstChunkDumper($this->logger());
        $gz_file = trailingslashit($this->backup_dir) . 'database-table-wp_options.sql.gz';

        ob_start();
        $dumper->stream_table_to_output_and_cache($this->backup_dir, 'wp_options');
        ob_end_clean();

        $this->assertGreaterThanOrEqual(2, $dumper->disconnect_checks);
        $this->assertFileDoesNotExist($gz_file);
        $this->assertSame(array(), $dumper->get_table_dump_if_exists($this->backup_dir, 'wp_options'));
    }

    public function test_complete_first_stream_caches_verified_gzip_without_checksum_transients(): void
    {
        $GLOBALS['wpdb'] = $this->options_wpdb(array(
            $this->option_row(1, 'siteurl', 'https://example.com'),
            $this->option_row(2, '_transient_stack2_cksum_aaa', 'deadbeef'),
            $this->option_row(3, '_transient_timeout_stack2_cksum_aaa', '1790022285'),
        ));

        $dumper = new Stack2_Database_Dumper($this->logger());
        ob_start();
        $dumper->stream_table_to_output_and_cache($this->backup_dir, 'wp_options');
        $streamed = ob_get_clean();

        $this->assertNotSame('', $streamed);
        $this->assertSame("\x1f\x8b", substr($streamed, 0, 2));

        $cached = $dumper->get_table_dump_if_exists($this->backup_dir, 'wp_options');
        $this->assertNotEmpty($cached);
        $this->assertFileExists($cached['file']);
        $this->assertTrue($this->invoke_verify($dumper, $cached['file']));

        $sql = $this->gunzip_file($cached['file']);
        $this->assertStringContainsString("'siteurl'", $sql);
        $this->assertStringNotContainsString('_transient_stack2_cksum_', $sql);
        $this->assertStringContainsString('SET FOREIGN_KEY_CHECKS=1', $sql);
        $this->assertSame(gzdecode($streamed), $sql);
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    private function options_wpdb(array $rows, string $table = 'wp_options'): TableDumpWpdb
    {
        $wpdb = new TableDumpWpdb();
        $wpdb->options = $table;
        $wpdb->tables[$table] = $rows;
        $wpdb->create_sql[$table] = "CREATE TABLE `{$table}` (\n"
            . "  `option_id` bigint NOT NULL AUTO_INCREMENT,\n"
            . "  `option_name` varchar(191) NOT NULL DEFAULT '',\n"
            . "  `option_value` longtext NOT NULL,\n"
            . "  `autoload` varchar(20) NOT NULL DEFAULT 'yes'\n"
            . ')';

        return $wpdb;
    }

    /**
     * @return array{option_id: int, option_name: string, option_value: string, autoload: string}
     */
    private function option_row(int $id, string $name, string $value): array
    {
        return array(
            'option_id' => $id,
            'option_name' => $name,
            'option_value' => $value,
            'autoload' => 'yes',
        );
    }

    private function gunzip_file(string $path): string
    {
        $bytes = file_get_contents($path);
        $this->assertNotFalse($bytes);
        $sql = gzdecode($bytes);
        $this->assertNotFalse($sql);

        return $sql;
    }

    private function invoke_verify(Stack2_Database_Dumper $dumper, string $path): bool
    {
        $method = new ReflectionMethod(Stack2_Database_Dumper::class, 'verify_dump');
        $method->setAccessible(true);

        return (bool) $method->invoke($dumper, $path);
    }
}

class TableDumpWpdb extends FakeWpdb
{
    /** @var array<string, array<int, array<string, mixed>>> */
    public array $tables = array();

    /** @var array<string, string> */
    public array $create_sql = array();

    /** @var array<int, string> */
    public array $queries = array();

    public function get_col($query)
    {
        $this->queries[] = (string) $query;
        if (stripos((string) $query, 'SHOW TABLES') !== false) {
            return array_keys($this->tables);
        }

        return array();
    }

    public function get_var($query)
    {
        $this->queries[] = (string) $query;
        $query = (string) $query;

        if (preg_match("/SHOW TABLES LIKE '(.+)'/i", $query, $matches)) {
            $want = $this->unescape_sql_like($matches[1]);
            return array_key_exists($want, $this->tables) ? $want : null;
        }

        if (preg_match('/SELECT COUNT\(\*\) FROM `([^`]+)`/i', $query, $matches)) {
            return (string) count($this->tables[$matches[1]] ?? array());
        }

        if (stripos($query, '@@collation') !== false) {
            return 'utf8mb4_unicode_ci';
        }

        return null;
    }

    public function get_row($query, $output = null)
    {
        $this->queries[] = (string) $query;
        if (preg_match('/SHOW CREATE TABLE `([^`]+)`/i', (string) $query, $matches)) {
            $name = $matches[1];
            $create = $this->create_sql[$name] ?? ('CREATE TABLE `' . $name . '` (`id` int)');
            return array($name, $create);
        }

        return null;
    }

    public function get_results($query, $output = null)
    {
        $this->queries[] = (string) $query;
        $query = (string) $query;

        if (stripos($query, 'SHOW TABLE STATUS') !== false) {
            $rows = array();
            foreach ($this->tables as $name => $data) {
                $rows[] = array(
                    'Name' => $name,
                    'Data_length' => 100,
                    'Index_length' => 10,
                );
            }
            return $rows;
        }

        if (!preg_match('/SELECT \* FROM `([^`]+)`(.*)$/is', $query, $matches)) {
            return parent::get_results($query, $output);
        }

        $name = $matches[1];
        $rest = $matches[2];
        $rows = $this->tables[$name] ?? array();

        if (stripos($rest, 'NOT LIKE') !== false) {
            $prefixes = array(
                '_transient_stack2_cksum_',
                '_transient_timeout_stack2_cksum_',
                '_site_transient_stack2_cksum_',
                '_site_transient_timeout_stack2_cksum_',
            );
            $rows = array_values(array_filter($rows, static function ($row) use ($prefixes) {
                $option = (string) ($row['option_name'] ?? '');
                foreach ($prefixes as $prefix) {
                    if (str_starts_with($option, $prefix)) {
                        return false;
                    }
                }
                return true;
            }));
        }

        $limit = 1000;
        $offset = 0;
        if (preg_match('/LIMIT\s+(\d+)\s+OFFSET\s+(\d+)/i', $rest, $limit_matches)) {
            $limit = (int) $limit_matches[1];
            $offset = (int) $limit_matches[2];
        }

        return array_slice($rows, $offset, $limit);
    }

    private function unescape_sql_like(string $quoted_pattern): string
    {
        $unquoted = stripslashes($quoted_pattern);

        return str_replace(
            array('\\_', '\\%', '\\\\'),
            array('_', '%', '\\'),
            $unquoted
        );
    }
}

class AbortAfterFirstChunkDumper extends Stack2_Database_Dumper
{
    public int $disconnect_checks = 0;

    protected function client_disconnected(): bool
    {
        $this->disconnect_checks++;

        return $this->disconnect_checks >= 2;
    }
}
