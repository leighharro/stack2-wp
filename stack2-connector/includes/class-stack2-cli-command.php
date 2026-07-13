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
        $server = isset($assoc_args['server']) ? esc_url_raw(trim((string) $assoc_args['server'])) : '';
        $site_id = isset($assoc_args['site-id']) ? sanitize_text_field((string) $assoc_args['site-id']) : '';
        $token = isset($assoc_args['token']) ? sanitize_text_field((string) $assoc_args['token']) : '';

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
}
