<?php

if (!defined('ABSPATH')) {
    exit;
}

class Stack2_Command_Executor
{
    private Stack2_Inventory_Collector $inventory_collector;
    private Stack2_Logger $logger;
    private string $site_id;
    private ?Stack2_Update_Checker $update_checker;

    public function __construct(
        Stack2_Inventory_Collector $inventory_collector,
        Stack2_Logger $logger,
        string $site_id,
        ?Stack2_Update_Checker $update_checker = null
    ) {
        $this->inventory_collector = $inventory_collector;
        $this->logger = $logger;
        $this->site_id = $site_id;
        $this->update_checker = $update_checker;
    }

    public function execute(string $action, ?string $plugin_file, ?string $slug, array $options = array()): array
    {
        try {
            switch ($action) {
                case 'inventory':
                    return array(
                        'success' => true,
                        'error' => null,
                        'inventory' => $this->inventory_collector->collect($this->site_id, !empty($options['refresh'])),
                    );

                case 'install':
                    return $this->install_plugin($slug);

                case 'update':
                    return $this->update_plugin($plugin_file, $slug);

                case 'update_core':
                    return $this->update_core($options);

                case 'activate':
                    return $this->activate_plugin($plugin_file, $slug);

                case 'deactivate':
                    return $this->deactivate_plugin($plugin_file, $slug);

                case 'delete':
                    return $this->delete_plugin($plugin_file, $slug);

                case 'disconnect':
                    return $this->disconnect();

                case 'check_updates':
                    return $this->check_updates();
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

        $this->ensure_plugin_upgrader_loaded();

        $was_active = function_exists('is_plugin_active') && is_plugin_active($resolved);
        $version_before = $this->installed_plugin_version($resolved);

        // Plugin_Upgrader::upgrade() does not reactivate the plugin afterward; only
        // bulk_upgrade() hooks active_after_upgrade to restore the pre-update active state.
        $skin = new Automatic_Upgrader_Skin();
        $upgrader = new Plugin_Upgrader($skin);
        $results = $upgrader->bulk_upgrade(array($resolved));
        $result = is_array($results) ? ($results[$resolved] ?? null) : $results;
        $message_skin = (isset($upgrader->skin) && is_object($upgrader->skin)) ? $upgrader->skin : $skin;
        $skin_messages = $this->upgrader_skin_messages($message_skin);

        if (is_wp_error($result) || empty($result)) {
            return $this->update_failed_response($result, $skin_messages, $version_before);
        }

        if (function_exists('wp_clean_plugins_cache')) {
            wp_clean_plugins_cache(true);
        }

        if ($was_active && function_exists('is_plugin_active') && !is_plugin_active($resolved) && function_exists('activate_plugin')) {
            activate_plugin($resolved);
        }

        $version_after = $this->installed_plugin_version($resolved);
        if ($this->plugin_version_unchanged($version_before, $version_after)) {
            $response = array(
                'success' => false,
                'error' => sprintf('Plugin update did not change the installed version (%s).', $version_after),
                'error_code' => 'not_applied',
                'not_applied' => true,
                'skin_messages' => $skin_messages,
                'inventory' => null,
            );
            if ($version_after !== null) {
                $response['plugin_version'] = $version_after;
            }

            return $response;
        }

        $response = array(
            'success' => true,
            'error' => null,
            'not_applied' => false,
            'inventory' => $this->inventory_collector->collect($this->site_id),
        );
        if ($version_after !== null) {
            $response['plugin_version'] = $version_after;
        }

        return $response;
    }

    /**
     * Keep a WordPress or vendor failure reason. The filesystem-credentials
     * string is only the fallback when neither the upgrader result nor the
     * skin reported a message.
     *
     * @param mixed $result
     * @param array<int, string> $skin_messages
     * @return array<string, mixed>
     */
    private function update_failed_response($result, array $skin_messages, ?string $plugin_version): array
    {
        $error_code = null;
        $error_message = null;

        if (is_wp_error($result)) {
            $code = trim((string) $result->get_error_code());
            $message = trim(wp_strip_all_tags((string) $result->get_error_message()));
            if ($code !== '') {
                $error_code = $code;
            }
            if ($message !== '') {
                $error_message = $message;
            }
        }

        if ($error_message === null && $skin_messages !== array()) {
            $error_message = implode(' ', $skin_messages);
        }

        if ($error_message === null) {
            $error_message = 'Plugin update failed. Filesystem credentials may be required.';
            if ($error_code === null) {
                $error_code = 'fs_credentials';
            }
        } elseif ($error_code === null) {
            $error_code = 'update_failed';
        }

        $response = array(
            'success' => false,
            'error' => $error_message,
            'error_code' => $error_code,
            'not_applied' => false,
            'skin_messages' => $skin_messages,
            'inventory' => null,
        );
        if ($plugin_version !== null && $plugin_version !== '') {
            $response['plugin_version'] = $plugin_version;
        }

        return $response;
    }

    /**
     * @return array<int, string>
     */
    private function upgrader_skin_messages(object $skin): array
    {
        if (!method_exists($skin, 'get_upgrade_messages')) {
            return array();
        }

        $messages = $skin->get_upgrade_messages();
        if (!is_array($messages)) {
            return array();
        }

        $clean = array();
        foreach ($messages as $message) {
            if (!is_scalar($message)) {
                continue;
            }
            $text = trim(wp_strip_all_tags((string) $message));
            if ($text === '') {
                continue;
            }
            $clean[] = $text;
        }

        return $clean;
    }

    private function installed_plugin_version(string $plugin_file): ?string
    {
        if (!function_exists('get_plugins')) {
            $path = ABSPATH . 'wp-admin/includes/plugin.php';
            if (is_readable($path)) {
                require_once $path;
            }
        }
        if (!function_exists('get_plugins')) {
            return null;
        }

        $plugins = get_plugins();
        if (!is_array($plugins) || !isset($plugins[$plugin_file]) || !is_array($plugins[$plugin_file])) {
            return null;
        }

        $version = trim(wp_strip_all_tags((string) ($plugins[$plugin_file]['Version'] ?? '')));

        return $version === '' ? null : $version;
    }

    private function plugin_version_unchanged(?string $before, ?string $after): bool
    {
        return $before !== null && $before !== '' && $after !== null && $after !== '' && $before === $after;
    }

    private function ensure_plugin_upgrader_loaded(): void
    {
        if (class_exists('Plugin_Upgrader') && class_exists('Automatic_Upgrader_Skin') && function_exists('is_plugin_active')) {
            return;
        }

        foreach (array(
            'wp-admin/includes/plugin.php',
            'wp-admin/includes/file.php',
            'wp-admin/includes/misc.php',
            'wp-admin/includes/class-wp-upgrader.php',
        ) as $relative) {
            $path = ABSPATH . $relative;
            if (is_readable($path)) {
                require_once $path;
            }
        }
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
        if (class_exists('Stack2_Restore_Script_Store')) {
            (new Stack2_Restore_Script_Store(null, $this->logger))->delete(null);
        }

        $this->logger->info('Disconnected from Stack2: credentials cleared.');

        return array('success' => true, 'error' => null, 'inventory' => null);
    }

    /**
     * Force an immediate Connector update check (clears WP/plugin caches).
     *
     * @return array<string, mixed>
     */
    private function check_updates(): array
    {
        $checker = $this->update_checker ?? new Stack2_Update_Checker($this->logger);
        $result = $checker->force_check();
        $success = !empty($result['success']);

        return array(
            'success' => $success,
            'error' => $result['error'] ?? ($success ? null : 'Update check failed.'),
            'inventory' => null,
            'status' => (string) ($result['status'] ?? ($success ? 'up_to_date' : 'check_failed')),
            'installed_version' => (string) ($result['installed_version'] ?? STACK2_CONNECTOR_VERSION),
            'available_version' => $result['available_version'] ?? null,
            'http_status' => $success ? 200 : 502,
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

    /**
     * Install one pinned WordPress release. Success is reported wp_version
     * equal to that release, not the upgrader's return string and not the
     * newest version-check offer.
     *
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function update_core(array $options): array
    {
        try {
            return $this->perform_core_update($options);
        } catch (Throwable $e) {
            $this->logger->error('Core update failed.', array(
                'action' => 'update_core',
                'error' => $e->getMessage(),
            ));

            return $this->core_update_response(
                false,
                'Core update failed.',
                'update_failed',
                array(),
                false,
                $this->reported_wp_version()
            );
        } finally {
            $this->remove_maintenance_file();
        }
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function perform_core_update(array $options): array
    {
        $version = isset($options['version']) && is_string($options['version']) ? trim($options['version']) : '';
        if (!Stack2_Core_Updater::is_pinned_release($version)) {
            return $this->core_update_response(
                false,
                'Core update requires a pinned numeric release (X.Y or X.Y.Z).',
                'invalid_version',
                array(),
                false,
                $this->reported_wp_version()
            );
        }

        $before = $this->reported_wp_version();
        $attempt = (new Stack2_Core_Updater())->upgrade($version);
        $after = $this->reported_wp_version();
        $skin_messages = $attempt['skin_messages'];
        $result = $attempt['result'];

        if ($after === $version) {
            return $this->core_update_response(true, null, null, array(), false, $after);
        }

        if ($after === $before) {
            if ($this->upgrader_reported_failure($result)) {
                return $this->core_update_failed_response($result, $skin_messages, $after);
            }

            $error = sprintf('Core update did not change the installed WordPress version (%s).', $after);

            return $this->core_update_response(false, $error, 'not_applied', $skin_messages, true, $after);
        }

        $shown = $after === '' ? 'unknown' : $after;
        $error = sprintf('Core update reported WordPress %s, not the pinned release %s.', $shown, $version);

        return $this->core_update_response(false, $error, 'version_mismatch', $skin_messages, false, $after);
    }

    /**
     * @param mixed $result
     */
    private function upgrader_reported_failure($result): bool
    {
        return is_wp_error($result) || $result === false || $result === null || $result === '';
    }

    /**
     * @param mixed $result
     * @param array<int, string> $skin_messages
     * @return array<string, mixed>
     */
    private function core_update_failed_response($result, array $skin_messages, string $installed_version): array
    {
        $error_code = null;
        $error_message = null;

        if (is_wp_error($result)) {
            $code = trim((string) $result->get_error_code());
            $message = trim(wp_strip_all_tags((string) $result->get_error_message()));
            if ($code !== '') {
                $error_code = $code;
            }
            if ($message !== '') {
                $error_message = $message;
            }
        }

        if ($error_message === null && $skin_messages !== array()) {
            $error_message = implode(' ', $skin_messages);
        }

        if ($error_message === null) {
            $error_message = 'Core update failed. Filesystem credentials may be required.';
            if ($error_code === null) {
                $error_code = 'fs_credentials';
            }
        } elseif ($error_code === null) {
            $error_code = 'update_failed';
        }

        return $this->core_update_response(false, $error_message, $error_code, $skin_messages, false, $installed_version);
    }

    /**
     * @param array<int, string> $skin_messages
     * @return array<string, mixed>
     */
    private function core_update_response(
        bool $success,
        ?string $error,
        ?string $error_code,
        array $skin_messages,
        bool $not_applied,
        string $installed_version
    ): array {
        $response = array(
            'success' => $success,
            'error' => $error,
            'error_message' => $error,
            'not_applied' => $not_applied,
            'installed_version' => $installed_version,
            'inventory' => $this->inventory_collector->collect($this->site_id),
        );

        if (!$success) {
            $response['error_code'] = $error_code;
            $response['skin_messages'] = $skin_messages;
        }

        return $response;
    }

    /**
     * update_core() loads the new version.php in a local scope and leaves the
     * request's $wp_version global on the old release. Read the file that the
     * next request will report.
     */
    private function reported_wp_version(): string
    {
        $disk = $this->wp_version_on_disk();
        if ($disk !== null) {
            $GLOBALS['wp_version'] = $disk;
            $GLOBALS['stack2_wp_version'] = $disk;

            return $disk;
        }

        if (!function_exists('get_bloginfo')) {
            return '';
        }

        $version = get_bloginfo('version');

        return is_string($version) ? trim($version) : '';
    }

    private function wp_version_on_disk(): ?string
    {
        if (!defined('ABSPATH')) {
            return null;
        }

        $path = rtrim((string) ABSPATH, '/\\') . '/wp-includes/version.php';
        if (!is_readable($path)) {
            return null;
        }

        $contents = file_get_contents($path, false, null, 0, 8192);
        if (!is_string($contents) || $contents === '') {
            return null;
        }

        if (!preg_match('/\$wp_version\s*=\s*([\'"])(\d+\.\d+(?:\.\d+)?)\1/', $contents, $matches)) {
            return null;
        }

        $version = $matches[2];
        if (!Stack2_Core_Updater::is_pinned_release($version)) {
            return null;
        }

        return $version;
    }

    private function remove_maintenance_file(): void
    {
        $paths = array();
        if (defined('ABSPATH')) {
            $paths[] = trailingslashit((string) ABSPATH) . '.maintenance';
        }

        $filesystem = $GLOBALS['wp_filesystem'] ?? null;
        if (is_object($filesystem) && is_callable(array($filesystem, 'abspath'))) {
            $remote = $filesystem->abspath();
            if (is_string($remote) && $remote !== '') {
                $paths[] = trailingslashit($remote) . '.maintenance';
            }
        }

        $paths = array_values(array_unique($paths));
        foreach ($paths as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }

        if (!is_object($filesystem) || !is_callable(array($filesystem, 'delete'))) {
            return;
        }

        foreach ($paths as $path) {
            try {
                $filesystem->delete($path);
            } catch (Throwable $e) {
                $this->logger->error('Could not remove the WordPress maintenance file.', array(
                    'action' => 'update_core',
                ));
            }
        }
    }
}
