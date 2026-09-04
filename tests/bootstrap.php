<?php

require_once __DIR__ . '/wordpress-stubs.php';

$plugin_includes = dirname(__DIR__) . '/stack2-connector/includes';

require_once $plugin_includes . '/class-stack2-logger.php';
require_once $plugin_includes . '/class-stack2-signature-service.php';
require_once $plugin_includes . '/class-stack2-backup-authentication.php';
require_once $plugin_includes . '/class-stack2-backup-compressor.php';
require_once $plugin_includes . '/class-stack2-database-dumper.php';
require_once $plugin_includes . '/class-stack2-backup-manifest.php';
require_once $plugin_includes . '/class-stack2-backup-manifest-store.php';
require_once $plugin_includes . '/class-stack2-backup-cleaner.php';
require_once $plugin_includes . '/class-stack2-backup-manager.php';
require_once $plugin_includes . '/class-stack2-backup-api.php';
