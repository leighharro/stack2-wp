<?php
/**
 * Plugin Name: Stack2 Connector
 * Description: Sync plugin inventory to Stack2 and execute signed remote plugin commands.
 * Version: 1.1.8
 * Author: Stack2
 * Requires at least: 6.0
* Requires PHP: 7.4
 * Update URI: https://github.com/leighharro/stack2-wp
 * Text Domain: stack2-connector
 */

if (!defined('ABSPATH')) {
    exit;
}

define('STACK2_CONNECTOR_VERSION', '1.1.8');
define('STACK2_CONNECTOR_PATH', plugin_dir_path(__FILE__));
define('STACK2_CONNECTOR_URL', plugin_dir_url(__FILE__));

require_once STACK2_CONNECTOR_PATH . 'includes/class-stack2-logger.php';
require_once STACK2_CONNECTOR_PATH . 'includes/class-stack2-signature-service.php';
require_once STACK2_CONNECTOR_PATH . 'includes/class-stack2-inventory-collector.php';
require_once STACK2_CONNECTOR_PATH . 'includes/class-stack2-http-client.php';
require_once STACK2_CONNECTOR_PATH . 'includes/class-stack2-command-executor.php';
require_once STACK2_CONNECTOR_PATH . 'includes/class-stack2-rest-controller.php';
require_once STACK2_CONNECTOR_PATH . 'includes/class-stack2-backup-authentication.php';
require_once STACK2_CONNECTOR_PATH . 'includes/class-stack2-sso-service.php';
require_once STACK2_CONNECTOR_PATH . 'includes/class-stack2-sso-api.php';
require_once STACK2_CONNECTOR_PATH . 'includes/class-stack2-backup-compressor.php';
require_once STACK2_CONNECTOR_PATH . 'includes/class-stack2-database-dumper.php';
require_once STACK2_CONNECTOR_PATH . 'includes/class-stack2-backup-manifest.php';
require_once STACK2_CONNECTOR_PATH . 'includes/class-stack2-backup-manifest-store.php';
require_once STACK2_CONNECTOR_PATH . 'includes/class-stack2-backup-cleaner.php';
require_once STACK2_CONNECTOR_PATH . 'includes/class-stack2-backup-manager.php';
require_once STACK2_CONNECTOR_PATH . 'includes/class-stack2-backup-api.php';
require_once STACK2_CONNECTOR_PATH . 'includes/class-stack2-settings-page.php';
require_once STACK2_CONNECTOR_PATH . 'includes/class-stack2-update-checker.php';
require_once STACK2_CONNECTOR_PATH . 'includes/class-stack2-plugin.php';

$stack2_connector_plugin = new Stack2_Plugin();
$stack2_connector_plugin->bootstrap();

register_activation_hook(__FILE__, array('Stack2_Plugin', 'on_activation'));
register_deactivation_hook(__FILE__, array('Stack2_Plugin', 'on_deactivation'));

if (defined('WP_CLI') && WP_CLI) {
    require_once STACK2_CONNECTOR_PATH . 'includes/class-stack2-cli-command.php';
    WP_CLI::add_command('stack2', new Stack2_CLI_Command($stack2_connector_plugin));
}
