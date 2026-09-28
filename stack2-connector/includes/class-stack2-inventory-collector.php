<?php

if (!defined('ABSPATH')) {
    exit;
}

class Stack2_Inventory_Collector
{
    public function collect(string $site_id, bool $refresh = false): array
    {
        if ($refresh) {
            $this->refresh_plugin_update_check();
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

        foreach ($all_plugins as $plugin_file => $plugin_data) {
            $slug = $this->slug_from_plugin_file($plugin_file);
            $update_data = $update_map[$plugin_file] ?? null;

            $plugins[] = array(
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
        }

        return array(
            'site_id' => $site_id,
            'site_url' => home_url('/'),
            'wp_version' => get_bloginfo('version'),
            'php_version' => PHP_VERSION,
            'collected_at' => gmdate('c'),
            'plugins' => $plugins,
        );
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
