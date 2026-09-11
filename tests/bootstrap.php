<?php

require_once __DIR__ . '/wordpress-stubs.php';

if (!defined('STACK2_CONNECTOR_PATH')) {
    define('STACK2_CONNECTOR_PATH', dirname(__DIR__) . '/stack2-connector/');
}

if (!defined('STACK2_CONNECTOR_VERSION')) {
    define('STACK2_CONNECTOR_VERSION', '1.1.18');
}

$plugin_includes = dirname(__DIR__) . '/stack2-connector/includes';

require_once $plugin_includes . '/class-stack2-logger.php';
require_once $plugin_includes . '/class-stack2-signature-service.php';
require_once $plugin_includes . '/class-stack2-backup-authentication.php';
require_once $plugin_includes . '/class-stack2-backup-compressor.php';
require_once $plugin_includes . '/class-stack2-database-dumper.php';
require_once $plugin_includes . '/class-stack2-backup-manifest.php';
require_once $plugin_includes . '/class-stack2-backup-file-scanner.php';
require_once $plugin_includes . '/class-stack2-backup-cleaner.php';
require_once $plugin_includes . '/class-stack2-backup-manager.php';
require_once $plugin_includes . '/class-stack2-backup-api.php';
require_once $plugin_includes . '/class-stack2-update-checker.php';
require_once $plugin_includes . '/class-stack2-inventory-collector.php';
require_once $plugin_includes . '/class-stack2-command-executor.php';
require_once $plugin_includes . '/class-stack2-rest-controller.php';
require_once $plugin_includes . '/class-stack2-plugin.php';
