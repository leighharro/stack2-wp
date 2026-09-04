<?php

if (!defined('ABSPATH')) {
    exit;
}

class Stack2_CLI_Command
{
    private Stack2_Plugin $plugin;

    public function __construct(Stack2_Plugin $plugin)
    {
        $this->plugin = $plugin;
    }

    /**
     * Configures the Stack2 Connector plugin with your Stack2 account details.
     *
     * ## OPTIONS
     *
     * --server=<url>
     * : Stack2 control server base URL.
     *
     * --site-id=<site_id>
     * : Site ID issued by Stack2 for this WordPress install.
     *
     * --token=<token>
     * : API key issued by Stack2 for this site.
     *
     * ## EXAMPLES
     *
     *     wp stack2 configure --server=https://api.stack2.au --site-id=abc123 --token=secret
     *
     * @when after_wp_load
     */
    public function configure(array $args, array $assoc_args): void
    {
        $server = isset($assoc_args['server']) ? (string) $assoc_args['server'] : '';
        $server = Stack2_Plugin::sanitize_credential($server);
        $server = esc_url_raw($server);
        $server = Stack2_Plugin::sanitize_credential($server);

        $site_id = isset($assoc_args['site-id']) ? (string) $assoc_args['site-id'] : '';
        $site_id = sanitize_text_field(Stack2_Plugin::sanitize_credential($site_id));

        $token = isset($assoc_args['token']) ? (string) $assoc_args['token'] : '';
        $token = sanitize_text_field(Stack2_Plugin::sanitize_credential($token));

        if ($server === '' || $site_id === '' || $token === '') {
            WP_CLI::error('--server, --site-id, and --token are all required.');
        }

        update_option(Stack2_Plugin::OPTION_BASE_URL, untrailingslashit($server));
        update_option(Stack2_Plugin::OPTION_SITE_ID, $site_id);
        update_option(Stack2_Plugin::OPTION_API_KEY, $token);

        $this->plugin->reschedule_cron();

        if ($this->plugin->has_valid_credentials()) {
            $this->plugin->schedule_single_sync(5, 'cli_configure', 0);
            WP_CLI::success('Stack2 Connector configured. Inventory sync queued.');
        } else {
            WP_CLI::success('Stack2 Connector configured.');
        }
    }

    /**
     * Immediately pushes plugin inventory to Stack2, bypassing the WP-Cron queue.
     *
     * ## EXAMPLES
     *
     *     wp stack2 sync
     *
     * @when after_wp_load
     */
    public function sync(array $args, array $assoc_args): void
    {
        if (!$this->plugin->has_valid_credentials()) {
            WP_CLI::error('Stack2 Connector is not configured. Run `wp stack2 configure` first.');
        }

        $result = $this->plugin->sync_inventory('cli_sync', 0);

        if (!empty($result['success'])) {
            WP_CLI::success('Inventory sync completed.');
        } else {
            WP_CLI::error('Inventory sync failed: ' . (string) ($result['error'] ?? 'Unknown error'));
        }
    }
}
