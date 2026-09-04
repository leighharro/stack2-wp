<?php

if (!defined('ABSPATH')) {
    define('ABSPATH', sys_get_temp_dir() . '/stack2-wp-abspath/');
}

if (!defined('WP_CONTENT_DIR')) {
    define('WP_CONTENT_DIR', rtrim(ABSPATH, '/\\') . '/wp-content');
}

if (!defined('HOUR_IN_SECONDS')) {
    define('HOUR_IN_SECONDS', 3600);
}

if (!defined('WEEK_IN_SECONDS')) {
    define('WEEK_IN_SECONDS', 604800);
}

if (!defined('MINUTE_IN_SECONDS')) {
    define('MINUTE_IN_SECONDS', 60);
}

if (!defined('DB_HOST')) {
    define('DB_HOST', 'localhost:3306');
}

if (!defined('DB_NAME')) {
    define('DB_NAME', 'wordpress');
}

if (!defined('DB_CHARSET')) {
    define('DB_CHARSET', 'utf8mb4');
}

if (!defined('DB_COLLATE')) {
    define('DB_COLLATE', 'utf8mb4_unicode_ci');
}

if (!defined('ARRAY_A')) {
    define('ARRAY_A', 'ARRAY_A');
}

$GLOBALS['stack2_transients'] = array();
$GLOBALS['stack2_options'] = array();
$GLOBALS['stack2_cron'] = array();

function trailingslashit($value)
{
    return rtrim((string) $value, '/\\') . '/';
}

function untrailingslashit($value)
{
    return rtrim((string) $value, '/\\');
}

function wp_normalize_path($path)
{
    $path = str_replace('\\', '/', (string) $path);
    $path = preg_replace('#/+#', '/', $path);

    return $path;
}

function wp_mkdir_p($target)
{
    return is_dir($target) || mkdir($target, 0777, true);
}

function wp_json_encode($data, $options = 0, $depth = 512)
{
    return json_encode($data, $options, $depth);
}

function get_transient($key)
{
    return $GLOBALS['stack2_transients'][$key] ?? false;
}

function set_transient($key, $value, $expiration = 0)
{
    $GLOBALS['stack2_transients'][$key] = $value;
    return true;
}

function get_option($key, $default = false)
{
    return $GLOBALS['stack2_options'][$key] ?? $default;
}

function update_option($key, $value)
{
    $GLOBALS['stack2_options'][$key] = $value;
    return true;
}

function get_bloginfo($show)
{
    return $show === 'version' ? '6.8' : '';
}

function get_site_url()
{
    return 'https://example.com';
}

function home_url($path = '/')
{
    return 'https://example.com' . $path;
}

function wp_upload_dir()
{
    return array(
        'basedir' => trailingslashit(WP_CONTENT_DIR) . 'uploads',
    );
}

function sanitize_text_field($value)
{
    return trim((string) $value);
}

function sanitize_key($key)
{
    $key = strtolower((string) $key);
    return preg_replace('/[^a-z0-9_\-]/', '', $key);
}

function wp_generate_uuid4()
{
    return '00000000-0000-4000-8000-000000000001';
}

function wp_is_uuid($uuid)
{
    return (bool) preg_match(
        '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
        (string) $uuid
    );
}

function wp_schedule_single_event($timestamp, $hook, $args = array())
{
    $GLOBALS['stack2_cron'][] = array(
        'timestamp' => $timestamp,
        'hook' => $hook,
        'args' => $args,
    );
    return true;
}

function wp_next_scheduled($hook, $args = array())
{
    foreach ($GLOBALS['stack2_cron'] as $event) {
        if ($event['hook'] === $hook && $event['args'] === $args) {
            return $event['timestamp'];
        }
    }

    return false;
}

function is_wp_error($thing)
{
    return $thing instanceof WP_Error;
}

class WP_Error
{
    private string $code;
    private string $message;
    private $data;

    public function __construct($code, $message, $data = array())
    {
        $this->code = (string) $code;
        $this->message = (string) $message;
        $this->data = $data;
    }

    public function get_error_code()
    {
        return $this->code;
    }

    public function get_error_message()
    {
        return $this->message;
    }

    public function get_error_data()
    {
        return $this->data;
    }
}

class WP_REST_Request
{
    private array $headers = array();
    private string $body = '';
    private string $route = '';
    private array $params = array();

    public function set_header(string $name, string $value): void
    {
        $this->headers[strtolower($name)] = $value;
    }

    public function get_header($name)
    {
        return $this->headers[strtolower((string) $name)] ?? '';
    }

    public function set_body(string $body): void
    {
        $this->body = $body;
    }

    public function get_body()
    {
        return $this->body;
    }

    public function set_route(string $route): void
    {
        $this->route = $route;
    }

    public function get_route()
    {
        return $this->route;
    }

    public function set_param(string $name, $value): void
    {
        $this->params[$name] = $value;
    }

    public function get_param($name)
    {
        return $this->params[$name] ?? null;
    }
}

class WP_REST_Response
{
    private $data;
    private int $status;

    public function __construct($data = null, $status = 200)
    {
        $this->data = $data;
        $this->status = (int) $status;
    }

    public function get_data()
    {
        return $this->data;
    }

    public function get_status()
    {
        return $this->status;
    }
}

class WP_REST_Server
{
    public const CREATABLE = 'POST';
    public const READABLE = 'GET';
    public const DELETABLE = 'DELETE';
}

class FakeWpdb
{
    public function get_results($query, $output = null)
    {
        return array(
            array('Name' => 'wp_options', 'Data_length' => 1024, 'Index_length' => 256),
            array('Name' => 'wp_posts', 'Data_length' => 2048, 'Index_length' => 512),
        );
    }

    public function get_var($query)
    {
        return 'utf8mb4_unicode_ci';
    }
}

$GLOBALS['wpdb'] = new FakeWpdb();
