<?php

if (!defined('ABSPATH')) {
    exit;
}

class Stack2_Database_Dumper
{
    private Stack2_Logger $logger;

    public function __construct(Stack2_Logger $logger)
    {
        $this->logger = $logger;
    }

    public function get_database_info(): array
    {
        global $wpdb;

        $host = defined('DB_HOST') ? DB_HOST : '';
        $host_parts = explode(':', $host, 2);

        // SHOW TABLE STATUS avoids information_schema locking on large databases.
        $table_status = $wpdb->get_results('SHOW TABLE STATUS', ARRAY_A);
        $tables = array();
        $size_bytes = 0;

        if (is_array($table_status)) {
            foreach ($table_status as $row) {
                $name = (string) ($row['Name'] ?? '');
                if ($name === '') {
                    continue;
                }
                $tables[] = $name;
                $size_bytes += (int) ($row['Data_length'] ?? 0) + (int) ($row['Index_length'] ?? 0);
            }
            sort($tables);
        }

        $collation = (string) $wpdb->get_var('SELECT @@collation_database');

        return array(
            'host' => (string) ($host_parts[0] ?? ''),
            'port' => isset($host_parts[1]) ? (int) $host_parts[1] : 3306,
            'name' => DB_NAME,
            'charset' => (string) DB_CHARSET,
            'collation' => $collation !== '' ? $collation : (string) DB_COLLATE,
            'size_bytes' => $size_bytes,
            'tables_count' => count($tables),
            'tables' => $tables,
        );
    }

    public function dump_database(string $temp_dir, callable $progress_callback): array
    {
        global $wpdb;

        $sql_file = trailingslashit($temp_dir) . 'wordpress-database.sql';
        $gz_file = $sql_file . '.gz';

        $tables = $wpdb->get_col('SHOW TABLES');
        if (!is_array($tables) || empty($tables)) {
            throw new RuntimeException('No database tables found for export.');
        }

        $rows_total = 0;
        foreach ($tables as $table) {
            $rows_total += (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$table}`");
        }
        $rows_total = max(1, $rows_total);

        $output = fopen($sql_file, 'wb');
        if ($output === false) {
            throw new RuntimeException('Unable to create SQL dump file.');
        }

        fwrite($output, "-- Stack2 WordPress backup\n");
        fwrite($output, '-- Generated at ' . gmdate('c') . "\n\n");
        fwrite($output, 'SET FOREIGN_KEY_CHECKS=0;' . "\n\n");

        $rows_processed = 0;

        foreach ($tables as $table) {
            $create = $wpdb->get_row("SHOW CREATE TABLE `{$table}`", ARRAY_N);
            if (!is_array($create) || !isset($create[1])) {
                continue;
            }

            fwrite($output, 'DROP TABLE IF EXISTS `' . $table . '`;' . "\n");
            fwrite($output, $create[1] . ';' . "\n\n");

            $numeric_columns = $this->numeric_columns_from_create_table($create[1]);

            $chunk_size = 1000;
            $offset = 0;
            $batch_size = 100;
            $batch_values = array();
            $columns_sql = '';

            while (true) {
                $rows = $wpdb->get_results(
                    $wpdb->prepare(
                        "SELECT * FROM `{$table}` LIMIT %d OFFSET %d",
                        $chunk_size,
                        $offset
                    ),
                    ARRAY_A
                );

                if (!is_array($rows) || empty($rows)) {
                    break;
                }

                foreach ($rows as $row) {
                    if ($columns_sql === '') {
                        $col_names = array_map(static function ($column) {
                            return '`' . str_replace('`', '``', (string) $column) . '`';
                        }, array_keys($row));
                        $columns_sql = '(' . implode(',', $col_names) . ')';
                    }

                    $values = array();
                    foreach ($row as $col_name => $value) {
                        $values[] = $this->sql_value($value, $numeric_columns[$col_name] ?? false);
                    }
                    $batch_values[] = '(' . implode(',', $values) . ')';
                    $rows_processed++;

                    if (count($batch_values) >= $batch_size) {
                        fwrite($output, 'INSERT INTO `' . $table . '` ' . $columns_sql . ' VALUES ' . implode(',', $batch_values) . ";\n");
                        $batch_values = array();
                    }

                    $progress_callback(array(
                        'phase' => 'database_dump',
                        'files_processed' => 0,
                        'files_total' => 0,
                        'database_rows_processed' => $rows_processed,
                        'database_rows_total' => $rows_total,
                        'bytes_processed' => 0,
                        'bytes_total' => 0,
                        'percent' => (int) min(99, floor(($rows_processed / $rows_total) * 100)),
                        'current_file' => $table,
                    ));
                }

                $offset += $chunk_size;
            }

            if (!empty($batch_values) && $columns_sql !== '') {
                fwrite($output, 'INSERT INTO `' . $table . '` ' . $columns_sql . ' VALUES ' . implode(',', $batch_values) . ";\n");
            }

            if ($columns_sql !== '') {
                fwrite($output, "\n");
            }
        }

        fwrite($output, 'SET FOREIGN_KEY_CHECKS=1;' . "\n");
        fclose($output);

        $this->compress_sql_dump($sql_file, $gz_file);

        if (!$this->verify_dump($gz_file)) {
            throw new RuntimeException('Database dump verification failed.');
        }

        @unlink($sql_file);

        return array(
            'file' => $gz_file,
            'size_bytes' => file_exists($gz_file) ? (int) filesize($gz_file) : 0,
            'rows_processed' => $rows_processed,
            'rows_total' => $rows_total,
        );
    }

    public function dump_database_table(string $temp_dir, string $table_name): array
    {
        global $wpdb;

        if (!preg_match('/^[A-Za-z0-9_\$]+$/', $table_name)) {
            throw new RuntimeException('Invalid database table name.');
        }

        // Allow the dump to complete even if the proxy closes the connection early (504).
        // PHP will finish writing the file so the next retry can serve the cached result.
        @set_time_limit(0);
        @ignore_user_abort(true);

        $safe_name = preg_replace('/[^A-Za-z0-9_\$]+/', '_', $table_name);
        $safe_name = is_string($safe_name) ? $safe_name : $table_name;

        $sql_file = trailingslashit($temp_dir) . 'database-table-' . $safe_name . '.sql';
        $gz_file = $sql_file . '.gz';

        // Cache check before the existence query — serves retries from disk without a DB round-trip.
        if (file_exists($gz_file) && $this->verify_dump($gz_file)) {
            return array(
                'file' => $gz_file,
                'size_bytes' => (int) filesize($gz_file),
                'table' => $table_name,
                'rows_processed' => 0,
            );
        }

        // SHOW TABLES LIKE is faster than information_schema.TABLES on large databases.
        $like = $wpdb->esc_like($table_name);
        $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $like));
        if ($found !== $table_name) {
            throw new RuntimeException('Requested database table is not available.');
        }

        $output = fopen($sql_file, 'wb');
        if ($output === false) {
            throw new RuntimeException('Unable to create table SQL dump file.');
        }

        fwrite($output, "-- Stack2 WordPress table backup\n");
        fwrite($output, '-- Table ' . $table_name . "\n");
        fwrite($output, '-- Generated at ' . gmdate('c') . "\n\n");
        fwrite($output, 'SET FOREIGN_KEY_CHECKS=0;' . "\n\n");

        $escaped_table = str_replace('`', '``', $table_name);
        $create = $wpdb->get_row("SHOW CREATE TABLE `{$escaped_table}`", ARRAY_N);
        if (!is_array($create) || !isset($create[1])) {
            fclose($output);
            @unlink($sql_file);
            throw new RuntimeException('Unable to export table schema.');
        }

        fwrite($output, 'DROP TABLE IF EXISTS `' . $escaped_table . '`;' . "\n");
        fwrite($output, $create[1] . ';' . "\n\n");

        $numeric_columns = $this->numeric_columns_from_create_table($create[1]);

        $rows_processed = 0;
        $chunk_size = 1000;
        $offset = 0;
        $batch_size = 100;
        $batch_values = array();
        $columns_sql = '';

        while (true) {
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM `{$escaped_table}` LIMIT %d OFFSET %d",
                    $chunk_size,
                    $offset
                ),
                ARRAY_A
            );

            if (!is_array($rows) || empty($rows)) {
                break;
            }

            foreach ($rows as $row) {
                if ($columns_sql === '') {
                    $col_names = array_map(static function ($column) {
                        return '`' . str_replace('`', '``', (string) $column) . '`';
                    }, array_keys($row));
                    $columns_sql = '(' . implode(',', $col_names) . ')';
                }

                $values = array();
                foreach ($row as $col_name => $value) {
                    $values[] = $this->sql_value($value, $numeric_columns[$col_name] ?? false);
                }
                $batch_values[] = '(' . implode(',', $values) . ')';
                $rows_processed++;

                if (count($batch_values) >= $batch_size) {
                    fwrite($output, 'INSERT INTO `' . $escaped_table . '` ' . $columns_sql . ' VALUES ' . implode(',', $batch_values) . ";\n");
                    $batch_values = array();
                }
            }

            $offset += $chunk_size;
        }

        if (!empty($batch_values) && $columns_sql !== '') {
            fwrite($output, 'INSERT INTO `' . $escaped_table . '` ' . $columns_sql . ' VALUES ' . implode(',', $batch_values) . ";\n");
        }

        fwrite($output, "\nSET FOREIGN_KEY_CHECKS=1;\n");
        fclose($output);

        $this->compress_sql_dump($sql_file, $gz_file);
        if (!$this->verify_dump($gz_file)) {
            @unlink($sql_file);
            throw new RuntimeException('Table dump verification failed.');
        }

        @unlink($sql_file);

        return array(
            'file' => $gz_file,
            'size_bytes' => file_exists($gz_file) ? (int) filesize($gz_file) : 0,
            'table' => $table_name,
            'rows_processed' => $rows_processed,
        );
    }

    public function stream_table_to_output_and_cache(string $temp_dir, string $table_name): void
    {
        global $wpdb;

        if (!preg_match('/^[A-Za-z0-9_\$]+$/', $table_name)) {
            throw new RuntimeException('Invalid database table name.');
        }

        @set_time_limit(0);
        @ignore_user_abort(true);

        $safe_name = preg_replace('/[^A-Za-z0-9_\$]+/', '_', $table_name);
        $safe_name = is_string($safe_name) ? $safe_name : $table_name;
        $gz_file = trailingslashit($temp_dir) . 'database-table-' . $safe_name . '.sql.gz';
        $escaped = str_replace('`', '``', $table_name);

        // gzopen requires a seekable stream; php://output is not seekable, so we
        // use deflate_init which works with any fwrite-capable handle.
        $ctx = deflate_init(ZLIB_ENCODING_GZIP, array('level' => 6));
        if ($ctx === false) {
            throw new RuntimeException('Unable to initialize gzip compressor.');
        }

        $out = fopen('php://output', 'wb');
        $cache_fh = fopen($gz_file, 'wb');

        if ($out === false) {
            if ($cache_fh !== false) {
                fclose($cache_fh);
            }
            throw new RuntimeException('Unable to open gzip output stream.');
        }

        if ($cache_fh === false) {
            fclose($out);
            throw new RuntimeException('Unable to create table SQL dump file.');
        }

        $write_compressed = function (string $raw, int $flush_mode) use ($ctx, $out, $cache_fh): void {
            $chunk = deflate_add($ctx, $raw, $flush_mode);
            if ($chunk !== false && $chunk !== '') {
                fwrite($out, $chunk);
                fwrite($cache_fh, $chunk);
            }
        };

        $success = false;
        try {
            $header = "-- Stack2 WordPress table backup\n"
                . '-- Table ' . $table_name . "\n"
                . '-- Generated at ' . gmdate('c') . "\n\n"
                . 'SET FOREIGN_KEY_CHECKS=0;' . "\n\n";

            $write_compressed($header, ZLIB_NO_FLUSH);

            $create = $wpdb->get_row("SHOW CREATE TABLE `{$escaped}`", ARRAY_N);
            if (!is_array($create) || !isset($create[1])) {
                throw new RuntimeException('Unable to export table schema.');
            }

            $schema = 'DROP TABLE IF EXISTS `' . $escaped . '`;' . "\n"
                . $create[1] . ';' . "\n\n";

            // Flush schema immediately so the proxy read timeout resets right away.
            $write_compressed($schema, ZLIB_SYNC_FLUSH);
            flush();

            $numeric_columns = $this->numeric_columns_from_create_table($create[1]);

            $chunk_size = 1000;
            $batch_size = 100;
            $offset = 0;
            $batch_values = array();
            $columns_sql = '';

            while (true) {
                $rows = $wpdb->get_results(
                    $wpdb->prepare(
                        "SELECT * FROM `{$escaped}` LIMIT %d OFFSET %d",
                        $chunk_size,
                        $offset
                    ),
                    ARRAY_A
                );

                if (!is_array($rows) || empty($rows)) {
                    break;
                }

                foreach ($rows as $row) {
                    if ($columns_sql === '') {
                        $col_names = array_map(static function ($col) {
                            return '`' . str_replace('`', '``', (string) $col) . '`';
                        }, array_keys($row));
                        $columns_sql = '(' . implode(',', $col_names) . ')';
                    }

                    $values = array();
                    foreach ($row as $col_name => $value) {
                        $values[] = $this->sql_value($value, $numeric_columns[$col_name] ?? false);
                    }
                    $batch_values[] = '(' . implode(',', $values) . ')';

                    if (count($batch_values) >= $batch_size) {
                        $sql = 'INSERT INTO `' . $escaped . '` ' . $columns_sql . ' VALUES ' . implode(',', $batch_values) . ";\n";
                        $write_compressed($sql, ZLIB_NO_FLUSH);
                        $batch_values = array();
                    }
                }

                // Flush each 1000-row chunk to prevent the proxy read timeout from firing.
                if (!empty($batch_values) && $columns_sql !== '') {
                    $sql = 'INSERT INTO `' . $escaped . '` ' . $columns_sql . ' VALUES ' . implode(',', $batch_values) . ";\n";
                    $write_compressed($sql, ZLIB_NO_FLUSH);
                    $batch_values = array();
                }

                $write_compressed('', ZLIB_SYNC_FLUSH);
                flush();

                if (connection_aborted()) {
                    break;
                }

                $offset += $chunk_size;
            }

            $footer = "\nSET FOREIGN_KEY_CHECKS=1;\n";
            $write_compressed($footer, ZLIB_FINISH);

            $success = true;
        } finally {
            fclose($out);
            fclose($cache_fh);

            if (!$success || !$this->verify_dump($gz_file)) {
                @unlink($gz_file);
            }
        }
    }

    public function get_table_dump_if_exists(string $temp_dir, string $table_name): array
    {
        if (!preg_match('/^[A-Za-z0-9_\$]+$/', $table_name)) {
            return array();
        }

        $safe_name = preg_replace('/[^A-Za-z0-9_\$]+/', '_', $table_name);
        $safe_name = is_string($safe_name) ? $safe_name : $table_name;
        $gz_file = trailingslashit($temp_dir) . 'database-table-' . $safe_name . '.sql.gz';

        if (!file_exists($gz_file) || !$this->verify_dump($gz_file)) {
            return array();
        }

        return array(
            'file' => $gz_file,
            'size_bytes' => (int) filesize($gz_file),
            'table' => $table_name,
            'rows_processed' => 0,
        );
    }

    public function table_exists(string $table_name): bool
    {
        global $wpdb;

        if (!preg_match('/^[A-Za-z0-9_\$]+$/', $table_name)) {
            return false;
        }

        return (int) $wpdb->get_var(
            $wpdb->prepare(
                'SELECT COUNT(*) FROM information_schema.TABLES WHERE table_schema = %s AND table_name = %s',
                DB_NAME,
                $table_name
            )
        ) > 0;
    }

    private function compress_sql_dump(string $sql_file, string $gz_file): void
    {
        // Use streaming compression to avoid loading entire file into memory
        $input = fopen($sql_file, 'rb');
        if ($input === false) {
            throw new RuntimeException('Unable to open SQL dump for compression.');
        }

        $output = gzopen($gz_file, 'wb6');
        if ($output === false) {
            fclose($input);
            throw new RuntimeException('Unable to create compressed dump file.');
        }

        try {
            while (!feof($input)) {
                $chunk = fread($input, 65536); // Read in 64KB chunks
                if ($chunk === false) {
                    throw new RuntimeException('Error reading SQL dump during compression.');
                }

                if (strlen($chunk) > 0) {
                    $written = gzwrite($output, $chunk);
                    if ($written === false) {
                        throw new RuntimeException('Error writing compressed data.');
                    }
                }
            }
        } finally {
            gzclose($output);
            fclose($input);
        }
    }

    private function verify_dump(string $dump_file): bool
    {
        if (!file_exists($dump_file)) {
            return false;
        }

        $size = filesize($dump_file);
        if ($size === false || (int) $size <= 0) {
            return false;
        }

        // Read and verify just the first chunk to avoid decompressing entire file
        $gz_file = gzopen($dump_file, 'rb');
        if ($gz_file === false) {
            return false;
        }

        try {
            $first_chunk = gzread($gz_file, 4096);
            if ($first_chunk === false || strlen($first_chunk) === 0) {
                return false;
            }

            return strpos($first_chunk, 'CREATE TABLE') !== false;
        } finally {
            gzclose($gz_file);
        }
    }

    /**
     * Maps column name => is-numeric-type, parsed from a SHOW CREATE TABLE
     * statement. Column definition lines always start with a backtick-quoted
     * name (index/key/constraint lines start with a keyword instead), so this
     * is safe without pulling in information_schema.
     */
    private function numeric_columns_from_create_table(string $create_sql): array
    {
        static $numeric_types = array(
            'tinyint', 'smallint', 'mediumint', 'int', 'integer', 'bigint',
            'decimal', 'numeric', 'float', 'double', 'real', 'bit', 'year',
        );

        $numeric = array();
        foreach (explode("\n", $create_sql) as $line) {
            if (preg_match('/^\s*`((?:[^`]|``)+)`\s+([a-zA-Z]+)/', $line, $m)) {
                $column = str_replace('``', '`', $m[1]);
                $numeric[$column] = in_array(strtolower($m[2]), $numeric_types, true);
            }
        }

        return $numeric;
    }

    private function sql_value($value, bool $is_numeric_column): string
    {
        if ($value === null) {
            return 'NULL';
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if ($is_numeric_column && is_numeric($value)) {
            return (string) $value;
        }

        return "'" . addslashes((string) $value) . "'";
    }
}
