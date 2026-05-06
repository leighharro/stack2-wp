<?php

if (!defined('ABSPATH')) {
    exit;
}

class Stack2_Inventory_Collector
{
    public function collect(string $site_id): array
    {
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $all_plugins = get_plugins();
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
                'is_active' => is_plugin_active($plugin_file),
                'has_update' => (bool) $update_data,
                'latest_version' => $update_data && isset($update_data->new_version)
                    ? sanitize_text_field((string) $update_data->new_version)
                    : null,
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
}
