<?php

if (!defined('ABSPATH')) {
    exit;
}

class Stack2_Command_Executor
{
    private Stack2_Inventory_Collector $inventory_collector;
    private Stack2_Logger $logger;
    private string $site_id;
    private ?Stack2_Backup_Service $backup_service;

    public function __construct(
        Stack2_Inventory_Collector $inventory_collector,
        Stack2_Logger $logger,
        string $site_id,
        ?Stack2_Backup_Service $backup_service = null
    ) {
        $this->inventory_collector = $inventory_collector;
        $this->logger = $logger;
        $this->site_id = $site_id;
        $this->backup_service = $backup_service;
    }

    public function execute(string $action, ?string $plugin_file, ?string $slug, array $payload = array()): array
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

                case 'backup':
                    return $this->initiate_backup($payload);

                case 'backup_cleanup':
                    return $this->cleanup_backup($payload);
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

        $upgrader = new Plugin_Upgrader(new Automatic_Upgrader_Skin());
        $result = $upgrader->upgrade($resolved);

        if (is_wp_error($result) || $result === false) {
            return array('success' => false, 'error' => 'Plugin update failed. Filesystem credentials may be required.', 'inventory' => null);
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

    private function initiate_backup(array $payload): array
    {
        if ($this->backup_service === null) {
            return array('success' => false, 'error' => 'Backup service is not available.', 'inventory' => null);
        }

        $backup_type = isset($payload['backup_type']) ? sanitize_text_field((string) $payload['backup_type']) : 'full';

        $result = $this->backup_service->initiate_backup($backup_type);
        if (!$result['success']) {
            return array('success' => false, 'error' => $result['error'] ?? 'Backup failed.', 'inventory' => null);
        }

        return array(
            'success' => true,
            'error' => null,
            'inventory' => null,
            'backup_id' => $result['backup_id'],
            'total_chunks' => $result['total_chunks'],
            'chunk_size' => $result['chunk_size'],
            'file_size' => $result['file_size'],
            'checksum' => $result['checksum'],
            'expires_at' => $result['expires_at'],
        );
    }

    private function cleanup_backup(array $payload): array
    {
        if ($this->backup_service === null) {
            return array('success' => false, 'error' => 'Backup service is not available.', 'inventory' => null);
        }

        $backup_id = isset($payload['backup_id']) ? sanitize_text_field((string) $payload['backup_id']) : '';
        if ($backup_id === '') {
            return array('success' => false, 'error' => 'backup_cleanup action requires backup_id.', 'inventory' => null);
        }

        $cleaned = $this->backup_service->cleanup($backup_id);

        return array(
            'success' => $cleaned,
            'error' => $cleaned ? null : 'Backup cleanup failed or backup not found.',
            'inventory' => null,
        );
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
