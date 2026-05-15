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

        $size_bytes = (int) $wpdb->get_var(
            $wpdb->prepare(
                'SELECT COALESCE(SUM(data_length + index_length), 0) FROM information_schema.TABLES WHERE table_schema = %s',
                DB_NAME
            )
        );

        $tables_count = (int) $wpdb->get_var(
            $wpdb->prepare(
                'SELECT COUNT(*) FROM information_schema.TABLES WHERE table_schema = %s',
                DB_NAME
            )
        );

        $tables = $wpdb->get_col(
            $wpdb->prepare(
                'SELECT TABLE_NAME FROM information_schema.TABLES WHERE table_schema = %s ORDER BY TABLE_NAME ASC',
                DB_NAME
            )
        );

        $collation = (string) $wpdb->get_var(
            $wpdb->prepare(
                'SELECT DEFAULT_COLLATION_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = %s',
                DB_NAME
            )
        );

        return array(
            'host' => (string) ($host_parts[0] ?? ''),
            'port' => isset($host_parts[1]) ? (int) $host_parts[1] : 3306,
            'name' => DB_NAME,
            'charset' => (string) DB_CHARSET,
            'collation' => $collation !== '' ? $collation : (string) DB_COLLATE,
            'size_bytes' => $size_bytes,
            'tables_count' => $tables_count,
            'tables' => is_array($tables) ? array_values($tables) : array(),
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

            // Fetch and process rows in chunks to avoid memory exhaustion
            $chunk_size = 1000;
            $offset = 0;
            $table_has_rows = false;

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

                $table_has_rows = true;

                foreach ($rows as $row) {
                    $columns = array_map(static function ($column) {
                        return '`' . str_replace('`', '``', (string) $column) . '`';
                    }, array_keys($row));

                    $values = array();
                    foreach ($row as $value) {
                        $values[] = $this->sql_value($value);
                    }

                    $sql = 'INSERT INTO `' . $table . '` (' . implode(',', $columns) . ') VALUES (' . implode(',', $values) . ');' . "\n";
                    fwrite($output, $sql);

                    $rows_processed++;
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

            if ($table_has_rows) {
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

        $table_exists = (int) $wpdb->get_var(
            $wpdb->prepare(
                'SELECT COUNT(*) FROM information_schema.TABLES WHERE table_schema = %s AND table_name = %s',
                DB_NAME,
                $table_name
            )
        );

        if ($table_exists <= 0) {
            throw new RuntimeException('Requested database table is not available.');
        }

        $safe_name = preg_replace('/[^A-Za-z0-9_\$]+/', '_', $table_name);
        $safe_name = is_string($safe_name) ? $safe_name : $table_name;

        $sql_file = trailingslashit($temp_dir) . 'database-table-' . $safe_name . '.sql';
        $gz_file = $sql_file . '.gz';

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

        // Fetch and process rows in chunks to avoid memory exhaustion
        $rows_processed = 0;
        $chunk_size = 1000;
        $offset = 0;

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
                $columns = array_map(static function ($column) {
                    return '`' . str_replace('`', '``', (string) $column) . '`';
                }, array_keys($row));

                $values = array();
                foreach ($row as $value) {
                    $values[] = $this->sql_value($value);
                }

                $sql = 'INSERT INTO `' . $escaped_table . '` (' . implode(',', $columns) . ') VALUES (' . implode(',', $values) . ');' . "\n";
                fwrite($output, $sql);
                $rows_processed++;
            }

            $offset += $chunk_size;
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

    private function sql_value($value): string
    {
        if ($value === null) {
            return 'NULL';
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_numeric($value)) {
            return (string) $value;
        }

        return "'" . addslashes((string) $value) . "'";
    }
}
