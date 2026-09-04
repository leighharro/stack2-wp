<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Persists a backup job's file inventory as append-only NDJSON and serves
 * bounded cursor pages. The filesystem walk/hash is resumable so shared
 * hosts never have to hash tens of thousands of files in one HTTP request.
 */
class Stack2_Backup_Manifest_Store
{
    public const DEFAULT_PAGE_SIZE = 1000;
    public const MAX_PAGE_SIZE = 2000;
    public const STATUS_PENDING = 'pending';
    public const STATUS_BUILDING = 'building';
    public const STATUS_READY = 'ready';
    public const STATUS_FAILED = 'failed';

    private const CHUNK_FILE_LIMIT = 400;
    private const CHUNK_SECONDS = 8.0;
    private const INDEX_FILE_LIMIT = 4000;

    private Stack2_Backup_Compressor $compressor;
    private Stack2_Logger $logger;
    private string $job_dir;
    private string $wordpress_root;
    private int $chunk_file_limit;
    private float $chunk_seconds;
    private int $index_file_limit;

    public function __construct(
        Stack2_Backup_Compressor $compressor,
        Stack2_Logger $logger,
        string $job_dir,
        string $wordpress_root,
        array $limits = array()
    ) {
        $this->compressor = $compressor;
        $this->logger = $logger;
        $this->job_dir = rtrim($job_dir, '/\\');
        $this->wordpress_root = $wordpress_root;
        $this->chunk_file_limit = isset($limits['chunk_file_limit'])
            ? max(1, (int) $limits['chunk_file_limit'])
            : self::CHUNK_FILE_LIMIT;
        $this->chunk_seconds = isset($limits['chunk_seconds'])
            ? max(0.1, (float) $limits['chunk_seconds'])
            : self::CHUNK_SECONDS;
        $this->index_file_limit = isset($limits['index_file_limit'])
            ? max(1, (int) $limits['index_file_limit'])
            : self::INDEX_FILE_LIMIT;
    }

    public static function normalize_limit($limit): int
    {
        $value = (int) $limit;
        if ($value <= 0) {
            return self::DEFAULT_PAGE_SIZE;
        }

        if ($value > self::MAX_PAGE_SIZE) {
            return self::MAX_PAGE_SIZE;
        }

        return $value;
    }

    public function initialize(string $backup_id, string $job_id, bool $include_files, bool $include_database, string $backup_started_at): void
    {
        wp_mkdir_p($this->job_dir);

        $job = array(
            'backup_id' => $backup_id,
            'job_id' => $job_id,
            'include_files' => $include_files,
            'include_database' => $include_database,
            'backup_started_at' => $backup_started_at !== '' ? $backup_started_at : gmdate('c'),
            'created_at' => gmdate('c'),
        );
        $this->write_json_atomic($this->job_path(), $job);

        $ready = !$include_files;
        $state = array(
            'status' => $ready ? self::STATUS_READY : self::STATUS_PENDING,
            'include_files' => $include_files,
            'index_complete' => $ready,
            'paths_count' => 0,
            'last_path' => '',
            'hash_offset' => 0,
            'files_written' => 0,
            'error' => null,
            'updated_at' => gmdate('c'),
        );
        $this->write_json_atomic($this->state_path(), $state);

        if ($include_files) {
            $this->touch_empty($this->paths_path());
            $this->touch_empty($this->files_path());
        }
    }

    public function job_exists(): bool
    {
        return is_file($this->job_path()) && is_readable($this->job_path());
    }

    public function read_job(): ?array
    {
        $job = $this->read_json($this->job_path());
        return is_array($job) ? $job : null;
    }

    public function read_state(): array
    {
        $state = $this->read_json($this->state_path());
        if (!is_array($state)) {
            return array(
                'status' => self::STATUS_FAILED,
                'include_files' => false,
                'index_complete' => false,
                'paths_count' => 0,
                'last_path' => '',
                'hash_offset' => 0,
                'files_written' => 0,
                'error' => 'Manifest state is missing.',
                'updated_at' => gmdate('c'),
            );
        }

        return $state;
    }

    /**
     * Returns a page payload. Builds a bounded chunk when the requested
     * records are not on disk yet. Never returns a short page as success
     * while the walk is still running.
     *
     * HTTP 202 means the requested page is not ready: files stay empty,
     * next_cursor is null, and has_more is true. Clients must retry the
     * same request cursor/query — they must not advance via next_cursor.
     *
     * @return array{payload: array, http_status: int}
     */
    public function get_page(?string $cursor, int $limit, bool $build_if_needed = true): array
    {
        $limit = self::normalize_limit($limit);

        try {
            $start_index = $this->decode_cursor($cursor);
        } catch (InvalidArgumentException $e) {
            return array(
                'payload' => array(
                    'success' => false,
                    'error' => $e->getMessage(),
                ),
                'http_status' => 400,
            );
        }

        $job = $this->read_job();
        if (!is_array($job)) {
            return array(
                'payload' => array(
                    'success' => false,
                    'error' => 'Backup job not found.',
                ),
                'http_status' => 404,
            );
        }

        $state = $this->read_state();
        if (($state['status'] ?? '') === self::STATUS_FAILED) {
            return array(
                'payload' => $this->error_payload($job, $state),
                'http_status' => 500,
            );
        }

        if (empty($job['include_files']) || empty($state['include_files'])) {
            return array(
                'payload' => $this->success_payload($job, $state, array(), null, false, $limit, true),
                'http_status' => 200,
            );
        }

        if ($build_if_needed && !$this->page_is_ready($state, $start_index, $limit)) {
            $this->build_chunk();
            $state = $this->read_state();
            if (($state['status'] ?? '') === self::STATUS_FAILED) {
                return array(
                    'payload' => $this->error_payload($job, $state),
                    'http_status' => 500,
                );
            }
        }

        try {
            return $this->read_ready_page($job, $state, $start_index, $limit);
        } catch (RuntimeException $e) {
            $this->logger->error('Refusing to return a corrupt manifest page.', array(
                'job_id' => $job['job_id'] ?? '',
                'error' => $e->getMessage(),
            ));

            return array(
                'payload' => array(
                    'success' => false,
                    'error' => $e->getMessage(),
                    'job_id' => (string) ($job['job_id'] ?? ''),
                    'backup_id' => (string) ($job['backup_id'] ?? ''),
                    'manifest_mode' => 'paged',
                    'manifest_status' => self::STATUS_FAILED,
                    'files' => array(),
                    'next_cursor' => null,
                    'has_more' => false,
                    'estimated_files_count' => $this->estimated_files_count($state),
                    'manifest_complete' => false,
                    'files_page_size' => $limit,
                ),
                'http_status' => 500,
            );
        }
    }

    /**
     * Continues the walk for one bounded chunk. Used by WP-Cron and GET.
     *
     * @return array state after the chunk
     */
    public function build_chunk(): array
    {
        $lock = $this->acquire_lock();
        if ($lock === null) {
            return $this->read_state();
        }

        try {
            $state = $this->read_state();
            if (in_array($state['status'] ?? '', array(self::STATUS_READY, self::STATUS_FAILED), true)) {
                return $state;
            }

            if (empty($state['include_files'])) {
                $state['status'] = self::STATUS_READY;
                $state['index_complete'] = true;
                $state['updated_at'] = gmdate('c');
                $this->write_json_atomic($this->state_path(), $state);
                return $state;
            }

            $state['status'] = self::STATUS_BUILDING;
            $state['updated_at'] = gmdate('c');
            $this->write_json_atomic($this->state_path(), $state);

            $deadline = microtime(true) + $this->chunk_seconds;

            if (empty($state['index_complete'])) {
                $state = $this->continue_index($state, $deadline);
                $this->write_json_atomic($this->state_path(), $state);
                if (empty($state['index_complete']) || ($state['status'] ?? '') === self::STATUS_FAILED) {
                    return $state;
                }
            }

            $state = $this->continue_hash($state, $deadline);
            $this->write_json_atomic($this->state_path(), $state);

            return $state;
        } catch (Throwable $e) {
            $state = $this->read_state();
            $state['status'] = self::STATUS_FAILED;
            $state['error'] = $e->getMessage();
            $state['updated_at'] = gmdate('c');
            $this->write_json_atomic($this->state_path(), $state);
            $this->logger->error('Backup manifest build failed.', array(
                'job_dir' => $this->job_dir,
                'error' => $e->getMessage(),
            ));

            return $state;
        } finally {
            $this->release_lock($lock);
        }
    }

    public function is_complete(): bool
    {
        $state = $this->read_state();
        return ($state['status'] ?? '') === self::STATUS_READY;
    }

    public function needs_more_work(): bool
    {
        $state = $this->read_state();
        $status = (string) ($state['status'] ?? '');

        return $status === self::STATUS_PENDING || $status === self::STATUS_BUILDING;
    }

    private function continue_index(array $state, float $deadline): array
    {
        $this->truncate_incomplete_tail($this->paths_path());

        $handle = fopen($this->paths_path(), 'ab');
        if ($handle === false) {
            throw new RuntimeException('Unable to open manifest path index for writing.');
        }

        $added = 0;
        try {
            $result = $this->compressor->collect_wp_content_paths(
                $this->wordpress_root,
                function (string $relative) use ($handle, &$added) {
                    if (fwrite($handle, $relative . "\n") === false) {
                        throw new RuntimeException('Failed to append path to manifest index.');
                    }
                    $added++;
                },
                (int) ($state['paths_count'] ?? 0),
                $this->index_file_limit,
                $deadline
            );
        } finally {
            fflush($handle);
            fclose($handle);
        }

        $state['paths_count'] = (int) ($state['paths_count'] ?? 0) + $added;
        $state['last_path'] = (string) ($result['last_path'] ?? $state['last_path'] ?? '');
        $state['updated_at'] = gmdate('c');

        if (!empty($result['complete'])) {
            $this->finalize_path_index($state);
        }

        return $state;
    }

    private function finalize_path_index(array &$state): void
    {
        $paths = $this->read_complete_lines($this->paths_path());
        $unique = array();
        foreach ($paths as $path) {
            $path = trim($path);
            if ($path === '') {
                continue;
            }
            $unique[$path] = true;
        }

        $sorted = array_keys($unique);
        sort($sorted, SORT_STRING);

        $tmp = $this->paths_path() . '.tmp';
        $body = $sorted === array() ? '' : implode("\n", $sorted) . "\n";
        if (file_put_contents($tmp, $body) === false) {
            throw new RuntimeException('Failed to finalize manifest path index.');
        }
        if (!rename($tmp, $this->paths_path())) {
            @unlink($tmp);
            throw new RuntimeException('Failed to replace manifest path index.');
        }

        $state['paths_count'] = count($sorted);
        $state['index_complete'] = true;
        $state['last_path'] = $sorted === array() ? '' : (string) $sorted[count($sorted) - 1];
    }

    private function continue_hash(array $state, float $deadline): array
    {
        $this->sync_files_file_to_expected((int) ($state['files_written'] ?? 0));

        $offset = (int) ($state['hash_offset'] ?? 0);
        $paths = $this->read_complete_lines($this->paths_path());
        $total_paths = count($paths);

        $files_handle = fopen($this->files_path(), 'ab');
        if ($files_handle === false) {
            throw new RuntimeException('Unable to open manifest files for writing.');
        }

        $written = 0;
        $processed = 0;

        try {
            for ($i = $offset; $i < $total_paths; $i++) {
                if ($processed >= $this->chunk_file_limit || microtime(true) >= $deadline) {
                    break;
                }

                $relative = trim($paths[$i]);
                $processed++;
                $offset = $i + 1;

                if ($relative === '') {
                    continue;
                }

                $metadata = $this->compressor->metadata_for_relative_path($relative);
                if ($metadata === null) {
                    continue;
                }

                $line = wp_json_encode($metadata);
                if ($line === false) {
                    throw new RuntimeException('Failed to encode manifest file entry.');
                }

                if (fwrite($files_handle, $line . "\n") === false) {
                    throw new RuntimeException('Failed to append manifest file entry.');
                }

                $written++;
            }
        } finally {
            fflush($files_handle);
            fclose($files_handle);
        }

        $state['hash_offset'] = $offset;
        $state['files_written'] = (int) ($state['files_written'] ?? 0) + $written;
        $state['updated_at'] = gmdate('c');

        if ($offset >= $total_paths) {
            $state['status'] = self::STATUS_READY;
            $state['error'] = null;
        } else {
            $state['status'] = self::STATUS_BUILDING;
        }

        return $state;
    }

    private function read_ready_page(array $job, array $state, int $start_index, int $limit): array
    {
        $complete = ($state['status'] ?? '') === self::STATUS_READY;
        $entries = $this->read_file_entries($start_index, $limit + 1);

        if (!$complete && count($entries) <= $limit) {
            // Still building and this page is short. Keep 202 with an empty
            // files list. Do not echo the request cursor as next_cursor —
            // Platform treats a repeated cursor as a loop. Clients retry the
            // same request cursor; has_more + manifest_complete:false signal
            // that more work remains.
            return array(
                'payload' => $this->success_payload($job, $state, array(), null, true, $limit, false),
                'http_status' => 202,
            );
        }

        $has_more = count($entries) > $limit;
        if ($has_more) {
            $entries = array_slice($entries, 0, $limit);
        }

        $next_index = $start_index + count($entries);
        $page_complete = $complete && !$has_more;

        return array(
            'payload' => $this->success_payload(
                $job,
                $state,
                $entries,
                $has_more ? $this->encode_cursor($next_index) : null,
                $has_more || !$complete,
                $limit,
                $page_complete
            ),
            'http_status' => 200,
        );
    }

    private function page_is_ready(array $state, int $start_index, int $limit): bool
    {
        if (($state['status'] ?? '') === self::STATUS_READY) {
            return true;
        }

        $available = (int) ($state['files_written'] ?? 0);
        return $available >= ($start_index + $limit);
    }

    private function read_file_entries(int $start_index, int $max_entries): array
    {
        $path = $this->files_path();
        if (!is_file($path)) {
            return array();
        }

        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return array();
        }

        $skipped = 0;
        $entries = array();

        try {
            while (($line = fgets($handle)) !== false) {
                if (substr($line, -1) !== "\n") {
                    break;
                }

                $line = trim($line);
                if ($line === '') {
                    continue;
                }

                if ($skipped < $start_index) {
                    $skipped++;
                    continue;
                }

                $decoded = json_decode($line, true);
                if (!is_array($decoded) || !isset($decoded['path'], $decoded['sha256'], $decoded['size'])) {
                    throw new RuntimeException('Corrupt manifest page; refusing to return a partial inventory.');
                }

                $sha256 = strtolower(trim((string) $decoded['sha256']));
                if (!preg_match('/^[a-f0-9]{64}$/', $sha256)) {
                    throw new RuntimeException('Corrupt manifest checksum; refusing to return a partial inventory.');
                }

                $entries[] = array(
                    'path' => (string) $decoded['path'],
                    'sha256' => $sha256,
                    'size' => (int) $decoded['size'],
                );

                if (count($entries) >= $max_entries) {
                    break;
                }
            }
        } finally {
            fclose($handle);
        }

        return $entries;
    }

    private function success_payload(
        array $job,
        array $state,
        array $files,
        ?string $next_cursor,
        bool $has_more,
        int $limit,
        bool $manifest_complete
    ): array {
        $status = (string) ($state['status'] ?? self::STATUS_PENDING);
        $estimated = $this->estimated_files_count($state);

        return array(
            'success' => true,
            'error' => null,
            'job_id' => (string) ($job['job_id'] ?? ''),
            'backup_id' => (string) ($job['backup_id'] ?? ''),
            'manifest_mode' => 'paged',
            'manifest_status' => $status,
            'files' => $files,
            'next_cursor' => $next_cursor,
            'has_more' => $has_more,
            'estimated_files_count' => $estimated,
            'manifest_complete' => $manifest_complete,
            'files_page_size' => $limit,
        );
    }

    private function error_payload(array $job, array $state): array
    {
        return array(
            'success' => false,
            'error' => (string) ($state['error'] ?? 'Manifest build failed.'),
            'job_id' => (string) ($job['job_id'] ?? ''),
            'backup_id' => (string) ($job['backup_id'] ?? ''),
            'manifest_mode' => 'paged',
            'manifest_status' => self::STATUS_FAILED,
            'files' => array(),
            'next_cursor' => null,
            'has_more' => false,
            'estimated_files_count' => $this->estimated_files_count($state),
            'manifest_complete' => false,
            'files_page_size' => self::DEFAULT_PAGE_SIZE,
        );
    }

    private function estimated_files_count(array $state): int
    {
        if (($state['status'] ?? '') === self::STATUS_READY) {
            return (int) ($state['files_written'] ?? 0);
        }

        if (!empty($state['index_complete'])) {
            return (int) ($state['paths_count'] ?? 0);
        }

        return (int) ($state['files_written'] ?? 0);
    }

    public function encode_cursor(int $index): string
    {
        $json = wp_json_encode(array('i' => $index));
        if ($json === false) {
            throw new RuntimeException('Failed to encode manifest cursor.');
        }

        return rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
    }

    public function decode_cursor(?string $cursor): int
    {
        if ($cursor === null || $cursor === '') {
            return 0;
        }

        $value = trim($cursor);
        if ($value === '') {
            return 0;
        }

        if (ctype_digit($value)) {
            return (int) $value;
        }

        $base64 = strtr($value, '-_', '+/');
        $padding = strlen($base64) % 4;
        if ($padding > 0) {
            $base64 .= str_repeat('=', 4 - $padding);
        }

        $decoded = base64_decode($base64, true);
        if ($decoded === false) {
            throw new InvalidArgumentException('Invalid manifest cursor.');
        }

        $payload = json_decode($decoded, true);
        if (!is_array($payload) || !isset($payload['i']) || !is_numeric($payload['i'])) {
            throw new InvalidArgumentException('Invalid manifest cursor.');
        }

        $index = (int) $payload['i'];
        if ($index < 0) {
            throw new InvalidArgumentException('Invalid manifest cursor.');
        }

        return $index;
    }

    private function sync_files_file_to_expected(int $expected_count): void
    {
        $this->truncate_incomplete_tail($this->files_path());
        $this->truncate_to_n_complete_lines($this->files_path(), $expected_count);
    }

    private function truncate_incomplete_tail(string $path): void
    {
        if (!is_file($path) || filesize($path) === 0) {
            return;
        }

        $size = (int) filesize($path);
        $handle = fopen($path, 'rb+');
        if ($handle === false) {
            return;
        }

        try {
            if ($size > 0) {
                fseek($handle, -1, SEEK_END);
                $last = fread($handle, 1);
                if ($last !== "\n") {
                    $keep = 0;
                    $chunk = 8192;
                    $pos = $size;
                    while ($pos > 0) {
                        $read = min($chunk, $pos);
                        $pos -= $read;
                        fseek($handle, $pos);
                        $data = fread($handle, $read);
                        if ($data === false) {
                            break;
                        }
                        $nl = strrpos($data, "\n");
                        if ($nl !== false) {
                            $keep = $pos + $nl + 1;
                            break;
                        }
                    }
                    ftruncate($handle, $keep);
                }
            }
        } finally {
            fclose($handle);
        }
    }

    private function truncate_to_n_complete_lines(string $path, int $keep_lines): void
    {
        if (!is_file($path) || $keep_lines < 0) {
            return;
        }

        if ($keep_lines === 0) {
            file_put_contents($path, '');
            return;
        }

        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return;
        }

        $kept = 0;
        $offset = 0;

        try {
            while (($line = fgets($handle)) !== false) {
                if (substr($line, -1) !== "\n") {
                    break;
                }
                $kept++;
                $offset = ftell($handle);
                if ($kept >= $keep_lines) {
                    break;
                }
            }
        } finally {
            fclose($handle);
        }

        if ($offset < filesize($path)) {
            $fh = fopen($path, 'rb+');
            if ($fh !== false) {
                ftruncate($fh, $offset);
                fclose($fh);
            }
        }
    }

    private function read_complete_lines(string $path): array
    {
        if (!is_file($path)) {
            return array();
        }

        $lines = array();
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return array();
        }

        try {
            while (($line = fgets($handle)) !== false) {
                if (substr($line, -1) !== "\n") {
                    break;
                }
                $lines[] = rtrim($line, "\r\n");
            }
        } finally {
            fclose($handle);
        }

        return $lines;
    }

    private function acquire_lock()
    {
        $handle = fopen($this->lock_path(), 'c+');
        if ($handle === false) {
            return null;
        }

        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            return null;
        }

        return $handle;
    }

    private function release_lock($handle): void
    {
        if (!is_resource($handle)) {
            return;
        }

        flock($handle, LOCK_UN);
        fclose($handle);
    }

    private function write_json_atomic(string $path, array $data): void
    {
        $json = wp_json_encode($data, JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new RuntimeException('Failed to encode manifest metadata.');
        }

        $tmp = $path . '.tmp';
        if (file_put_contents($tmp, $json) === false) {
            throw new RuntimeException('Failed to write manifest metadata.');
        }

        if (!rename($tmp, $path)) {
            @unlink($tmp);
            throw new RuntimeException('Failed to replace manifest metadata.');
        }
    }

    private function read_json(string $path): ?array
    {
        if (!is_file($path) || !is_readable($path)) {
            return null;
        }

        $raw = file_get_contents($path);
        if ($raw === false || $raw === '') {
            return null;
        }

        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : null;
    }

    private function touch_empty(string $path): void
    {
        if (!is_file($path)) {
            file_put_contents($path, '');
        }
    }

    private function job_path(): string
    {
        return $this->job_dir . '/job.json';
    }

    private function state_path(): string
    {
        return $this->job_dir . '/manifest-state.json';
    }

    private function paths_path(): string
    {
        return $this->job_dir . '/paths.ndjson';
    }

    private function files_path(): string
    {
        return $this->job_dir . '/files.ndjson';
    }

    private function lock_path(): string
    {
        return $this->job_dir . '/manifest.lock';
    }
}
