<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Plugin inventory for Stack2.
 *
 * On WordPress 6.5 and newer, each plugin includes requires_plugins and
 * has_circular_dependency from WP_Plugin_Dependencies. Both keys are omitted
 * on older WordPress (they are not sent as an empty array or false).
 * Optional WooCommerce and Elementor compatibility headers are included only
 * when that plugin file declares them. Dependency data is read locally:
 * collecting it does not call WordPress.org.
 *
 * refresh true also forces wp_version_check() and adds inventory.core_update.
 * The key is omitted when that check does not run.
 */
class Stack2_Inventory_Collector
{
    private const COMPATIBILITY_HEADERS = array(
        'wc_requires_at_least' => 'WC requires at least',
        'wc_tested_up_to' => 'WC tested up to',
        'elementor_tested_up_to' => 'Elementor tested up to',
        'elementor_pro_tested_up_to' => 'Elementor Pro tested up to',
    );

    public function collect(string $site_id, bool $refresh = false): array
    {
        $core_update = null;
        if ($refresh) {
            $this->refresh_plugin_update_check();
            $core_update = $this->refresh_core_update_check();
        }

        if (!function_exists('get_plugins')) {
            $plugin_file = ABSPATH . 'wp-admin/includes/plugin.php';
            if (is_readable($plugin_file)) {
                require_once $plugin_file;
            }
        }

        $all_plugins = function_exists('get_plugins') ? get_plugins() : array();
        $updates = get_site_transient('update_plugins');
        $update_map = is_object($updates) && isset($updates->response) && is_array($updates->response)
            ? $updates->response
            : array();

        $plugins = array();
        $dependencies_supported = $this->wordpress_supports_plugin_dependencies();
        if ($dependencies_supported) {
            $this->initialize_plugin_dependencies();
        }

        foreach ($all_plugins as $plugin_file => $plugin_data) {
            $slug = $this->slug_from_plugin_file($plugin_file);
            $update_data = $update_map[$plugin_file] ?? null;

            $plugin = array(
                'slug' => $slug,
                'file' => $plugin_file,
                'name' => sanitize_text_field($plugin_data['Name'] ?? ''),
                'version' => sanitize_text_field($plugin_data['Version'] ?? ''),
                'author' => sanitize_text_field(wp_strip_all_tags($plugin_data['Author'] ?? '')),
                'plugin_uri' => esc_url_raw($plugin_data['PluginURI'] ?? ''),
                'description' => sanitize_textarea_field(wp_strip_all_tags($plugin_data['Description'] ?? '')),
                'is_active' => function_exists('is_plugin_active') && is_plugin_active($plugin_file),
                'has_update' => (bool) $update_data,
                'latest_version' => $this->latest_version($update_data),
                'update_package_available' => $this->update_package_available($update_data),
                'upgrade_notice' => $this->upgrade_notice($update_data),
            );

            if ($dependencies_supported) {
                $plugin['requires_plugins'] = $this->required_plugin_slugs($plugin_file);
                $plugin['has_circular_dependency'] = (bool) WP_Plugin_Dependencies::has_circular_dependency($plugin_file);
            }

            foreach ($this->compatibility_headers($plugin_file) as $header_key => $header_value) {
                $plugin[$header_key] = $header_value;
            }

            $plugins[] = $plugin;
        }

        $inventory = array(
            'site_id' => $site_id,
            'site_url' => home_url('/'),
            'wp_version' => get_bloginfo('version'),
            'php_version' => PHP_VERSION,
            'collected_at' => gmdate('c'),
            'plugins' => $plugins,
        );

        if (is_array($core_update)) {
            $inventory['core_update'] = $core_update;
        }

        return $inventory;
    }

    /**
     * WordPress 6.5 introduced WP_Plugin_Dependencies. Older releases omit
     * the dependency keys entirely.
     */
    private function wordpress_supports_plugin_dependencies(): bool
    {
        if (!class_exists('WP_Plugin_Dependencies')) {
            return false;
        }

        foreach (array('initialize', 'get_dependencies', 'has_circular_dependency') as $method) {
            if (!is_callable(array('WP_Plugin_Dependencies', $method))) {
                return false;
            }
        }

        return $this->wordpress_version_supports_plugin_dependencies($this->wordpress_version());
    }

    private function wordpress_version(): string
    {
        if (!function_exists('get_bloginfo')) {
            return '';
        }

        $version = get_bloginfo('version');

        return is_string($version) ? trim($version) : '';
    }

    /**
     * Class presence is the feature gate. A parsed major.minor below 6.5
     * still omits the fields (for example 6.4.5). Unparsable versions keep
     * the fields when the class exists, so a 6.5 release candidate is included.
     */
    private function wordpress_version_supports_plugin_dependencies(string $version): bool
    {
        if ($version === '') {
            return true;
        }

        if (!preg_match('/^(\d+)\.(\d+)/', $version, $matches)) {
            return true;
        }

        $major = (int) $matches[1];
        $minor = (int) $matches[2];

        return $major > 6 || ($major === 6 && $minor >= 5);
    }

    /**
     * initialize() also loads WordPress.org dependency info when the current
     * screen is plugins.php. Slugs and circular checks come from local
     * headers, so short-circuit that lookup for this call.
     */
    private function initialize_plugin_dependencies(): void
    {
        $block_plugins_api = function ($result, $action = null, $args = null) {
            unset($action, $args);
            if (false !== $result) {
                return $result;
            }

            return new WP_Error(
                'stack2_plugin_dependencies_offline',
                'Plugin dependency fields are read locally and do not contact WordPress.org.'
            );
        };
        $block_http = function ($preempt, $args = array(), $url = '') {
            unset($args);
            if (false !== $preempt) {
                return $preempt;
            }
            if (!$this->request_targets_wordpress_org($url)) {
                return $preempt;
            }

            return new WP_Error(
                'stack2_plugin_dependencies_offline',
                'Plugin dependency fields are read locally and do not contact WordPress.org.'
            );
        };

        $filters_available = function_exists('add_filter') && function_exists('remove_filter');
        if ($filters_available) {
            add_filter('plugins_api', $block_plugins_api, PHP_INT_MAX, 3);
            add_filter('pre_http_request', $block_http, PHP_INT_MAX, 3);
        }

        try {
            WP_Plugin_Dependencies::initialize();
        } finally {
            if ($filters_available) {
                remove_filter('plugins_api', $block_plugins_api, PHP_INT_MAX);
                remove_filter('pre_http_request', $block_http, PHP_INT_MAX);
            }
        }
    }

    /**
     * @param mixed $url
     */
    private function request_targets_wordpress_org($url): bool
    {
        if (!is_string($url) || $url === '') {
            return false;
        }

        $host = parse_url($url, PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            return false;
        }

        $host = strtolower(rtrim($host, '.'));

        return $host === 'wordpress.org' || substr($host, -strlen('.wordpress.org')) === '.wordpress.org';
    }

    /**
     * @return array<int, string>
     */
    private function required_plugin_slugs(string $plugin_file): array
    {
        $raw = WP_Plugin_Dependencies::get_dependencies($plugin_file);
        if (!is_array($raw)) {
            return array();
        }

        $slugs = array();
        foreach ($raw as $slug) {
            if (!is_scalar($slug)) {
                continue;
            }
            $slug = trim((string) $slug);
            if ($slug === '') {
                continue;
            }
            $slugs[] = $slug;
        }

        return $slugs;
    }

    /**
     * @return array<string, string>
     */
    private function compatibility_headers(string $plugin_file): array
    {
        if (!function_exists('get_file_data') || !defined('WP_PLUGIN_DIR')) {
            return array();
        }

        $path = $this->plugin_file_path($plugin_file);
        if ($path === null) {
            return array();
        }

        $headers = get_file_data($path, self::COMPATIBILITY_HEADERS, 'plugin');
        if (!is_array($headers)) {
            return array();
        }

        $present = array();
        foreach (array_keys(self::COMPATIBILITY_HEADERS) as $key) {
            if (!array_key_exists($key, $headers) || !is_scalar($headers[$key])) {
                continue;
            }
            $value = sanitize_text_field((string) $headers[$key]);
            if ($value === '') {
                continue;
            }
            $present[$key] = $value;
        }

        return $present;
    }

    private function plugin_file_path(string $plugin_file): ?string
    {
        $plugin_file = str_replace('\\', '/', $plugin_file);
        $plugin_file = ltrim($plugin_file, '/');
        if ($plugin_file === '' || str_contains($plugin_file, "\0") || str_contains($plugin_file, '..')) {
            return null;
        }

        $root = wp_normalize_path(trailingslashit(WP_PLUGIN_DIR));
        $path = wp_normalize_path($root . $plugin_file);
        if (!str_starts_with($path, $root)) {
            return null;
        }

        return $path;
    }

    private function slug_from_plugin_file(string $plugin_file): string
    {
        $parts = explode('/', $plugin_file);
        return sanitize_title($parts[0] ?? $plugin_file);
    }

    /**
     * Drop a fresh update_plugins transient so wp_update_plugins() does not
     * bail out on last_checked, then let WordPress repopulate it.
     */
    private function refresh_plugin_update_check(): void
    {
        if (!function_exists('wp_update_plugins')) {
            $update_file = ABSPATH . 'wp-admin/includes/update.php';
            if (is_readable($update_file)) {
                require_once $update_file;
            }
        }

        if (!function_exists('wp_update_plugins')) {
            return;
        }

        delete_site_transient('update_plugins');
        wp_update_plugins();
    }

    /**
     * Force a core version check and return the offers WordPress stored.
     *
     * wp_version_check() skips the HTTP request for one minute unless
     * $force_check is true. Refresh passes true so the offer list is the
     * one WordPress just checked. The update_core transient is left in
     * place: a failed request keeps the previous offers instead of looking
     * like WordPress reported none.
     *
     * Null when the check cannot run. Callers omit core_update in that
     * case. A failure is swallowed so this refresh still returns plugin
     * inventory. Missing core_update means the check did not finish.
     *
     * @return array{checked: true, updates: array<int, array<string, mixed>>}|null
     */
    private function refresh_core_update_check(): ?array
    {
        try {
            $this->load_core_update_api();
            if (!function_exists('wp_version_check') || !function_exists('get_core_updates')) {
                return null;
            }

            wp_version_check(array(), true);

            return array(
                'checked' => true,
                'updates' => $this->normalize_core_updates(get_core_updates()),
            );
        } catch (Throwable) {
            return null;
        }
    }

    private function load_core_update_api(): void
    {
        if (!function_exists('wp_version_check')) {
            $core_file = ABSPATH . 'wp-includes/update.php';
            if (is_readable($core_file)) {
                require_once $core_file;
            }
        }

        if (!function_exists('get_core_updates')) {
            $admin_file = ABSPATH . 'wp-admin/includes/update.php';
            if (is_readable($admin_file)) {
                require_once $admin_file;
            }
        }
    }

    /**
     * get_core_updates() returns a list of objects, or false when WordPress
     * has no offer list. JSON keeps every public property. False and any
     * other non-list become an empty array.
     *
     * @param mixed $updates
     * @return array<int, array<string, mixed>>
     */
    private function normalize_core_updates($updates): array
    {
        if (!is_array($updates)) {
            return array();
        }

        $rows = array();
        foreach ($updates as $update) {
            if (is_object($update)) {
                $update = get_object_vars($update);
            }
            if (!is_array($update)) {
                continue;
            }

            $row = $this->core_update_json_value($update);
            if (is_array($row)) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * @param mixed $value
     * @return mixed
     */
    private function core_update_json_value($value)
    {
        if (is_object($value)) {
            $value = get_object_vars($value);
        }
        if (!is_array($value)) {
            if (is_string($value) || is_int($value) || is_float($value) || is_bool($value) || $value === null) {
                return $value;
            }

            return null;
        }

        $is_list = $this->is_list_array($value);
        $normalized = array();
        foreach ($value as $key => $item) {
            $item = $this->core_update_json_value($item);
            if ($is_list) {
                $normalized[] = $item;
                continue;
            }
            if (!is_string($key) || $key === '') {
                continue;
            }
            $normalized[$key] = $item;
        }

        return $normalized;
    }

    /**
     * @param array<mixed> $value
     */
    private function is_list_array(array $value): bool
    {
        $expected = 0;
        foreach ($value as $key => $unused) {
            unset($unused);
            if ($key !== $expected) {
                return false;
            }
            $expected++;
        }

        return true;
    }

    /**
     * @param mixed $update_data
     */
    private function latest_version($update_data): ?string
    {
        if (!$this->update_record_has($update_data, 'new_version')) {
            return null;
        }

        $version = $this->update_record_value($update_data, 'new_version');
        if (!is_scalar($version)) {
            return null;
        }

        $version = sanitize_text_field((string) $version);

        return $version === '' ? null : $version;
    }

    /**
     * True/false when the update transient has a package field; null when
     * WordPress has not said whether a download package exists.
     *
     * @param mixed $update_data
     */
    private function update_package_available($update_data): ?bool
    {
        if (!$this->update_record_has($update_data, 'package')) {
            return null;
        }

        $package = $this->update_record_value($update_data, 'package');

        return is_string($package) && trim($package) !== '';
    }

    /**
     * @param mixed $update_data
     */
    private function upgrade_notice($update_data): ?string
    {
        if (!$this->update_record_has($update_data, 'upgrade_notice')) {
            return null;
        }

        $notice = $this->update_record_value($update_data, 'upgrade_notice');
        if (!is_string($notice)) {
            return null;
        }

        $notice = trim(wp_strip_all_tags($notice));

        return $notice === '' ? null : $notice;
    }

    /**
     * @param mixed $update_data
     */
    private function update_record_has($update_data, string $field): bool
    {
        if (is_object($update_data)) {
            return property_exists($update_data, $field);
        }
        if (is_array($update_data)) {
            return array_key_exists($field, $update_data);
        }

        return false;
    }

    /**
     * @param mixed $update_data
     * @return mixed
     */
    private function update_record_value($update_data, string $field)
    {
        if (is_object($update_data)) {
            return $update_data->{$field};
        }
        if (is_array($update_data)) {
            return $update_data[$field];
        }

        return null;
    }
}
