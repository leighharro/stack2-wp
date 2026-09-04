<?php

if (!defined('ABSPATH')) {
    exit;
}

class Stack2_Settings_Page
{
    private Stack2_Plugin $plugin;

    public function __construct(Stack2_Plugin $plugin)
    {
        $this->plugin = $plugin;

        add_action('admin_menu', array($this, 'register_menu'));
        add_action('admin_post_stack2_save_settings', array($this, 'handle_save_settings'));
        add_action('admin_post_stack2_sync_now', array($this, 'handle_sync_now'));
        add_action('admin_post_stack2_check_updates', array($this, 'handle_check_updates'));
        add_action('admin_init', array($this, 'maybe_redirect_after_activation'));
        add_action('admin_notices', array($this, 'maybe_render_setup_notice'));
    }

    public function maybe_redirect_after_activation(): void
    {
        if (!get_transient(Stack2_Plugin::ACTIVATION_REDIRECT_TRANSIENT)) {
            return;
        }

        delete_transient(Stack2_Plugin::ACTIVATION_REDIRECT_TRANSIENT);

        if (wp_doing_ajax() || (defined('WP_CLI') && WP_CLI) || isset($_GET['activate-multi'])) {
            return;
        }

        if (!current_user_can('manage_options')) {
            return;
        }

        wp_safe_redirect(admin_url('options-general.php?page=stack2-connector'));
        exit;
    }

    public function maybe_render_setup_notice(): void
    {
        if (!current_user_can('manage_options') || $this->plugin->has_valid_credentials()) {
            return;
        }

        $screen = get_current_screen();
        if ($screen && $screen->id === 'settings_page_stack2-connector') {
            return;
        }

        printf(
            '<div class="notice notice-warning"><p>%s <a href="%s">%s</a></p></div>',
            esc_html__('Stack2 Connector is active but not yet configured.', 'stack2-connector'),
            esc_url(admin_url('options-general.php?page=stack2-connector')),
            esc_html__('Complete setup now', 'stack2-connector')
        );
    }

    public function register_menu(): void
    {
        add_options_page(
            'Stack2 Connector',
            'Stack2 Connector',
            'manage_options',
            'stack2-connector',
            array($this, 'render_page')
        );
    }

    public function render_page(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $base_url = (string) get_option(Stack2_Plugin::OPTION_BASE_URL, '');
        $site_id = (string) get_option(Stack2_Plugin::OPTION_SITE_ID, '');
        $api_key = (string) get_option(Stack2_Plugin::OPTION_API_KEY, '');
        $auto_sync = (bool) get_option(Stack2_Plugin::OPTION_AUTO_SYNC_ENABLED, true);
        $interval = (int) get_option(Stack2_Plugin::OPTION_SYNC_INTERVAL_MINUTES, 60);
        $last_at = (string) get_option(Stack2_Plugin::OPTION_LAST_SYNC_AT, '');
        $last_status = (string) get_option(Stack2_Plugin::OPTION_LAST_SYNC_STATUS, 'never');
        $last_error = (string) get_option(Stack2_Plugin::OPTION_LAST_SYNC_ERROR, '');
        $debug = (bool) get_option(Stack2_Logger::OPTION_DEBUG, false);
        $release_info = $this->plugin->get_update_checker()->get_cached_release_info();

        $masked_api_key = $this->mask_api_key($api_key);
        $notice = get_transient('stack2_admin_notice');
        if ($notice) {
            delete_transient('stack2_admin_notice');
        }

        $permalink_structure = (string) get_option('permalink_structure', '');

        ?>
        <div class="wrap">
            <h1>Stack2 Connector</h1>
            <?php if (is_array($notice) && !empty($notice['message'])) : ?>
                <div class="notice notice-<?php echo esc_attr($notice['type'] ?? 'info'); ?> is-dismissible">
                    <p><?php echo esc_html($notice['message']); ?></p>
                </div>
            <?php endif; ?>

            <?php if ($permalink_structure === '') : ?>
                <div class="notice notice-warning">
                    <p>
                        <strong>Permalinks are set to "Plain".</strong>
                        Stack2's REST endpoints (<code>/wp-json/stack2/v1/...</code>) — used for remote commands,
                        backups, and WordPress admin SSO — require a non-default permalink structure to work.
                        Please visit <a href="<?php echo esc_url(admin_url('options-permalink.php')); ?>">Settings &rsaquo; Permalinks</a>
                        and choose any option other than "Plain", then save.
                    </p>
                </div>
            <?php endif; ?>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('stack2_save_settings'); ?>
                <input type="hidden" name="action" value="stack2_save_settings" />
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="stack2_base_url">Stack2 Base URL</label></th>
                        <td><input name="stack2_base_url" id="stack2_base_url" type="url" class="regular-text" value="<?php echo esc_attr($base_url); ?>" required /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="stack2_site_id">Site ID</label></th>
                        <td><input name="stack2_site_id" id="stack2_site_id" type="text" class="regular-text" value="<?php echo esc_attr($site_id); ?>" required /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="stack2_api_key_new">API Key</label></th>
                        <td>
                            <input name="stack2_api_key_new" id="stack2_api_key_new" type="password" class="regular-text" autocomplete="new-password" />
                            <?php if ($masked_api_key !== '') : ?>
                                <p class="description">Saved key: <?php echo esc_html($masked_api_key); ?>. Leave blank to keep current key.</p>
                            <?php else : ?>
                                <p class="description">Enter the API key from Stack2.</p>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Auto Sync Enabled</th>
                        <td><label><input name="stack2_auto_sync_enabled" type="checkbox" value="1" <?php checked($auto_sync); ?> /> Enable scheduled inventory sync</label></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="stack2_sync_interval_minutes">Sync Interval (minutes)</label></th>
                        <td><input name="stack2_sync_interval_minutes" id="stack2_sync_interval_minutes" type="number" min="5" max="1440" value="<?php echo esc_attr((string) $interval); ?>" /></td>
                    </tr>
                    <tr>
                        <th scope="row">Debug Logging</th>
                        <td><label><input name="stack2_debug_enabled" type="checkbox" value="1" <?php checked($debug); ?> /> Enable detailed STACK2_PLUGIN logs</label></td>
                    </tr>
                </table>

                <?php submit_button('Save Settings'); ?>
            </form>

            <hr />

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('stack2_sync_now'); ?>
                <input type="hidden" name="action" value="stack2_sync_now" />
                <?php submit_button('Sync Now', 'secondary', 'submit', false); ?>
            </form>

            <h2>Last Sync</h2>
            <p><strong>Status:</strong> <?php echo esc_html($last_status); ?></p>
            <p><strong>At:</strong> <?php echo esc_html($last_at === '' ? 'N/A' : $last_at); ?></p>
            <p><strong>Error:</strong> <?php echo esc_html($last_error === '' ? 'None' : $last_error); ?></p>

            <hr />

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('stack2_check_updates'); ?>
                <input type="hidden" name="action" value="stack2_check_updates" />
                <?php submit_button('Check for Updates', 'secondary', 'submit', false); ?>
            </form>

            <h2>Updates</h2>
            <p><strong>Current Version:</strong> <?php echo esc_html(STACK2_CONNECTOR_VERSION); ?></p>
            <p><strong>Latest Known Version:</strong> <?php echo esc_html($release_info !== null ? $release_info['version'] : 'Unknown (not checked yet)'); ?></p>
            <p><strong>Last Checked:</strong> <?php echo esc_html($release_info !== null ? gmdate('c', $release_info['checked_at']) : 'Never'); ?></p>
            <p class="description">Updates are fetched from <a href="https://github.com/leighharro/stack2-wp/releases/latest" target="_blank" rel="noopener noreferrer">GitHub Releases</a> and installed the same way as any other WordPress plugin update, including WordPress's built-in "Enable auto-updates" option on the Plugins page.</p>
        </div>
        <?php
    }

    public function handle_save_settings(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Forbidden', 403);
        }

        check_admin_referer('stack2_save_settings');

        $base_url = isset($_POST['stack2_base_url']) ? esc_url_raw(trim((string) wp_unslash($_POST['stack2_base_url']))) : '';
        $site_id = isset($_POST['stack2_site_id']) ? sanitize_text_field((string) wp_unslash($_POST['stack2_site_id'])) : '';
        $api_key_new = isset($_POST['stack2_api_key_new']) ? sanitize_text_field((string) wp_unslash($_POST['stack2_api_key_new'])) : '';
        $auto_sync = !empty($_POST['stack2_auto_sync_enabled']) ? 1 : 0;
        $interval = isset($_POST['stack2_sync_interval_minutes']) ? absint($_POST['stack2_sync_interval_minutes']) : 60;
        $debug = !empty($_POST['stack2_debug_enabled']) ? 1 : 0;

        if ($interval < 5) {
            $interval = 5;
        }
        if ($interval > 1440) {
            $interval = 1440;
        }

        update_option(Stack2_Plugin::OPTION_BASE_URL, untrailingslashit($base_url));
        update_option(Stack2_Plugin::OPTION_SITE_ID, $site_id);
        update_option(Stack2_Plugin::OPTION_AUTO_SYNC_ENABLED, $auto_sync);
        update_option(Stack2_Plugin::OPTION_SYNC_INTERVAL_MINUTES, $interval);
        update_option(Stack2_Logger::OPTION_DEBUG, $debug);

        if ($api_key_new !== '') {
            update_option(Stack2_Plugin::OPTION_API_KEY, $api_key_new);
        }

        $this->plugin->reschedule_cron();

        if ($this->plugin->has_valid_credentials()) {
            $result = $this->plugin->sync_inventory('settings_save', 0);
            $notice_type = $result['success'] ? 'success' : 'error';
            $message = $result['success']
                ? 'Settings saved and inventory sync completed successfully.'
                : 'Settings saved, but inventory sync failed: ' . ($result['error'] ?? 'Unknown error');
        } else {
            $notice_type = 'success';
            $message = 'Settings saved. Add valid credentials to enable sync.';
        }

        set_transient('stack2_admin_notice', array('type' => $notice_type, 'message' => $message), 30);
        wp_safe_redirect(admin_url('options-general.php?page=stack2-connector'));
        exit;
    }

    public function handle_sync_now(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Forbidden', 403);
        }

        check_admin_referer('stack2_sync_now');

        if (!$this->plugin->has_valid_credentials()) {
            set_transient('stack2_admin_notice', array('type' => 'error', 'message' => 'Cannot sync: Stack2 credentials are not configured.'), 30);
            wp_safe_redirect(admin_url('options-general.php?page=stack2-connector'));
            exit;
        }

        $result = $this->plugin->sync_inventory('manual', 0);
        $notice_type = $result['success'] ? 'success' : 'error';
        $message = $result['success'] ? 'Manual sync completed successfully.' : 'Manual sync failed: ' . ($result['error'] ?? 'Unknown error');

        set_transient('stack2_admin_notice', array('type' => $notice_type, 'message' => $message), 30);
        wp_safe_redirect(admin_url('options-general.php?page=stack2-connector'));
        exit;
    }

    public function handle_check_updates(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Forbidden', 403);
        }

        check_admin_referer('stack2_check_updates');

        $result = $this->plugin->get_update_checker()->force_check();

        if (!$result['success']) {
            $message = 'Update check failed: ' . ($result['error'] ?? 'Unknown error');
            $notice_type = 'error';
        } elseif ($result['has_update']) {
            $message = sprintf('Update available: version %s. Visit the Plugins page to install it.', $result['latest_version']);
            $notice_type = 'success';
        } else {
            $message = sprintf('Already up to date (version %s).', STACK2_CONNECTOR_VERSION);
            $notice_type = 'success';
        }

        set_transient('stack2_admin_notice', array('type' => $notice_type, 'message' => $message), 30);
        wp_safe_redirect(admin_url('options-general.php?page=stack2-connector'));
        exit;
    }

    private function mask_api_key(string $api_key): string
    {
        if ($api_key === '') {
            return '';
        }

        if (strlen($api_key) <= 8) {
            return str_repeat('*', strlen($api_key));
        }

        return substr($api_key, 0, 4) . str_repeat('*', max(4, strlen($api_key) - 8)) . substr($api_key, -4);
    }
}
