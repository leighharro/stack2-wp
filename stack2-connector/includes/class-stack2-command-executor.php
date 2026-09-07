<?php

if (!defined('ABSPATH')) {
    exit;
}

class Stack2_Command_Executor
{
    private Stack2_Inventory_Collector $inventory_collector;
    private Stack2_Logger $logger;
    private string $site_id;

    public function __construct(Stack2_Inventory_Collector $inventory_collector, Stack2_Logger $logger, string $site_id)
    {
        $this->inventory_collector = $inventory_collector;
        $this->logger = $logger;
        $this->site_id = $site_id;
    }

    public function execute(string $action, ?string $plugin_file, ?string $slug): array
    {
        try {
            switch ($action) {
                case 'inventory':
                    return array('success' => true, 'error' => null, 'inventory' => $this->inventory_collector->collect($this->site_id));

                case 'install':
                    return $this->install_plugin($slug);

                case 'update':
                    return $this->update_plugin($plugin_file, $slug);

                case 'activate':
                    return $this->activate_plugin($plugin_file, $slug);

                case 'deactivate':
                    return $this->deactivate_plugin($plugin_file, $slug);

                case 'delete':
                    return $this->delete_plugin($plugin_file, $slug);

                case 'disconnect':
                    return $this->disconnect();
            }

            return array('success' => false, 'error' => 'Unsupported action.', 'inventory' => null);
        } catch (Throwable $e) {
            $this->logger->error('Command execution failed.', array('action' => $action, 'error' => $e->getMessage()));
            return array('success' => false, 'error' => 'Command execution failed.', 'inventory' => null);
        }
    }

    private function install_plugin(?string $slug): array
    {
        if (empty($slug)) {
            return array('success' => false, 'error' => 'Install action requires slug.', 'inventory' => null);
        }

        require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/misc.php';

        $api = plugins_api('plugin_information', array('slug' => sanitize_title($slug), 'fields' => array('sections' => false)));
        if (is_wp_error($api) || empty($api->download_link)) {
            return array('success' => false, 'error' => 'Unable to fetch plugin package for install.', 'inventory' => null);
        }

        $upgrader = new Plugin_Upgrader(new Automatic_Upgrader_Skin());
        $result = $upgrader->install($api->download_link);

        if (is_wp_error($result) || $result === false) {
            return array('success' => false, 'error' => 'Plugin install failed. Filesystem credentials may be required.', 'inventory' => null);
        }

        return array('success' => true, 'error' => null, 'inventory' => $this->inventory_collector->collect($this->site_id));
    }

    private function update_plugin(?string $plugin_file, ?string $slug): array
    {
        $resolved = $this->resolve_plugin_file($plugin_file, $slug);
        if (!$resolved) {
            return array('success' => false, 'error' => 'Update action requires plugin file or resolvable slug.', 'inventory' => null);
        }

        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/misc.php';
        require_once ABSPATH . 'wp-admin/includes/plugin.php';

        $was_active = is_plugin_active($resolved);

        // Plugin_Upgrader::upgrade() does not reactivate the plugin afterward; only
        // bulk_upgrade() hooks active_after_upgrade to restore the pre-update active state.
        $upgrader = new Plugin_Upgrader(new Automatic_Upgrader_Skin());
        $results = $upgrader->bulk_upgrade(array($resolved));
        $result = is_array($results) ? ($results[$resolved] ?? null) : $results;

        if (is_wp_error($result) || empty($result)) {
            return array('success' => false, 'error' => 'Plugin update failed. Filesystem credentials may be required.', 'inventory' => null);
        }

        if ($was_active && !is_plugin_active($resolved)) {
            activate_plugin($resolved);
        }

        return array('success' => true, 'error' => null, 'inventory' => $this->inventory_collector->collect($this->site_id));
    }

    private function activate_plugin(?string $plugin_file, ?string $slug): array
    {
        if (!function_exists('activate_plugin')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $resolved = $this->resolve_plugin_file($plugin_file, $slug);
        if (!$resolved) {
            return array('success' => false, 'error' => 'Activate action requires plugin file or resolvable slug.', 'inventory' => null);
        }

        $result = activate_plugin($resolved);
        if (is_wp_error($result)) {
            return array('success' => false, 'error' => $result->get_error_message(), 'inventory' => null);
        }

        return array('success' => true, 'error' => null, 'inventory' => $this->inventory_collector->collect($this->site_id));
    }

    private function deactivate_plugin(?string $plugin_file, ?string $slug): array
    {
        if (!function_exists('deactivate_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $resolved = $this->resolve_plugin_file($plugin_file, $slug);
        if (!$resolved) {
            return array('success' => false, 'error' => 'Deactivate action requires plugin file or resolvable slug.', 'inventory' => null);
        }

        deactivate_plugins($resolved, false, false);

        return array('success' => true, 'error' => null, 'inventory' => $this->inventory_collector->collect($this->site_id));
    }

    private function delete_plugin(?string $plugin_file, ?string $slug): array
    {
        if (!function_exists('delete_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $resolved = $this->resolve_plugin_file($plugin_file, $slug);
        if (!$resolved) {
            return array('success' => false, 'error' => 'Delete action requires plugin file or resolvable slug.', 'inventory' => null);
        }

        if (is_plugin_active($resolved)) {
            deactivate_plugins($resolved, false, false);
        }

        $result = delete_plugins(array($resolved));
        if (is_wp_error($result) || $result === false) {
            return array('success' => false, 'error' => 'Plugin delete failed. Filesystem credentials may be required.', 'inventory' => null);
        }

        return array('success' => true, 'error' => null, 'inventory' => $this->inventory_collector->collect($this->site_id));
    }

    /**
     * Platform-initiated disconnect: drop the local HMAC secret and routing IDs
     * while the inbound request is still signed with the current key.
     */
    private function disconnect(): array
    {
        delete_option(Stack2_Plugin::OPTION_BASE_URL);
        delete_option(Stack2_Plugin::OPTION_SITE_ID);
        delete_option(Stack2_Plugin::OPTION_API_KEY);
        // Live WP schedules this hook with args (recurring [0,"cron"], plus
        // single events like [n,"retry"]). wp_clear_scheduled_hook($hook)
        // only removes the empty-args variant; unschedule the whole hook.
        if (function_exists('wp_unschedule_hook')) {
            wp_unschedule_hook(Stack2_Plugin::CRON_HOOK_SYNC);
        }
        wp_clear_scheduled_hook(Stack2_Plugin::CRON_HOOK_SYNC, array(0, 'cron'));
        delete_transient('stack2_sync_lock');

        $this->logger->info('Disconnected from Stack2: credentials cleared.');

        return array('success' => true, 'error' => null, 'inventory' => null);
    }

    private function resolve_plugin_file(?string $plugin_file, ?string $slug): ?string
    {
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        if (!empty($plugin_file)) {
            $sanitized = plugin_basename(sanitize_text_field($plugin_file));
            $plugins = get_plugins();
            if (isset($plugins[$sanitized])) {
                return $sanitized;
            }
        }

        if (empty($slug)) {
            return null;
        }

        $target_slug = sanitize_title($slug);
        foreach (array_keys(get_plugins()) as $installed_file) {
            $parts = explode('/', $installed_file);
            if (($parts[0] ?? '') === $target_slug) {
                return $installed_file;
            }
        }

        return null;
    }
}
