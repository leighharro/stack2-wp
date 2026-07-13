<?php

if (!defined('ABSPATH')) {
    exit;
}

class Stack2_Plugin
{
    public const OPTION_BASE_URL = 'stack2_base_url';
    public const OPTION_SITE_ID = 'stack2_site_id';
    public const OPTION_API_KEY = 'stack2_api_key';
    public const OPTION_AUTO_SYNC_ENABLED = 'stack2_auto_sync_enabled';
    public const OPTION_SYNC_INTERVAL_MINUTES = 'stack2_sync_interval_minutes';
    public const OPTION_LAST_SYNC_AT = 'stack2_last_sync_at';
    public const OPTION_LAST_SYNC_STATUS = 'stack2_last_sync_status';
    public const OPTION_LAST_SYNC_ERROR = 'stack2_last_sync_error';

    public const CRON_HOOK_SYNC = 'stack2_sync_inventory_event';
    private const LOCK_TRANSIENT = 'stack2_sync_lock';

    private Stack2_Logger $logger;
    private Stack2_Signature_Service $signature_service;
    private Stack2_Inventory_Collector $inventory_collector;
    private Stack2_Http_Client $http_client;
    private Stack2_Backup_Manager $backup_manager;
    private Stack2_Update_Checker $update_checker;
    private Stack2_SSO_Service $sso_service;

    public function __construct()
    {
        $this->logger = new Stack2_Logger();
        $this->signature_service = new Stack2_Signature_Service();
        $this->inventory_collector = new Stack2_Inventory_Collector();
        $this->http_client = new Stack2_Http_Client($this->signature_service);
        $this->update_checker = new Stack2_Update_Checker($this->logger);
        $this->sso_service = new Stack2_SSO_Service($this->logger);

        $this->backup_manager = new Stack2_Backup_Manager(
            $this->logger,
            new Stack2_Backup_Compressor($this->logger),
            new Stack2_Database_Dumper($this->logger),
            new Stack2_Backup_Manifest(),
            new Stack2_Backup_Cleaner()
        );
    }

    public function bootstrap(): void
    {
        add_action('rest_api_init', array($this, 'register_rest_controller'));
        add_action('init', array($this->sso_service, 'maybe_complete_login'), 1);
        add_action(self::CRON_HOOK_SYNC, array($this, 'handle_scheduled_sync'), 10, 2);
        add_filter('cron_schedules', array($this, 'register_cron_schedule'));

        $this->update_checker->bootstrap();

        new Stack2_Settings_Page($this);
    }

    public static function on_activation(): void
    {
        if (get_option(self::OPTION_AUTO_SYNC_ENABLED, null) === null) {
            update_option(self::OPTION_AUTO_SYNC_ENABLED, 1);
        }
        if (get_option(self::OPTION_SYNC_INTERVAL_MINUTES, null) === null) {
            update_option(self::OPTION_SYNC_INTERVAL_MINUTES, 60);
        }

        $plugin = new self();
        $plugin->reschedule_cron();
        if ($plugin->has_valid_credentials()) {
            $plugin->schedule_single_sync(10, 'activation', 0);
        }
    }

    public static function on_deactivation(): void
    {
        wp_clear_scheduled_hook(self::CRON_HOOK_SYNC);
        delete_transient(self::LOCK_TRANSIENT);
    }

    public function register_rest_controller(): void
    {
        $executor = new Stack2_Command_Executor(
            $this->inventory_collector,
            $this->logger,
            $this->get_site_id()
        );

        $controller = new Stack2_REST_Controller(
            $this->signature_service,
            $executor,
            $this->logger,
            $this->get_site_id(),
            $this->get_api_key()
        );

        $controller->register_routes();

        $backup_api = new Stack2_Backup_API(
            $this->backup_manager,
            new Stack2_Backup_Authentication($this->signature_service),
            $this->logger,
            $this->get_site_id(),
            $this->get_api_key()
        );

        $backup_api->register_routes();

        $sso_api = new Stack2_SSO_API(
            $this->sso_service,
            new Stack2_Backup_Authentication($this->signature_service),
            $this->logger,
            $this->get_site_id(),
            $this->get_api_key()
        );

        $sso_api->register_routes();
    }

    public function register_cron_schedule(array $schedules): array
    {
        $minutes = $this->get_sync_interval_minutes();
        $schedules['stack2_custom'] = array(
            'interval' => $minutes * MINUTE_IN_SECONDS,
            'display' => sprintf('Every %d minutes (Stack2)', $minutes),
        );

        return $schedules;
    }

    public function reschedule_cron(): void
    {
        wp_clear_scheduled_hook(self::CRON_HOOK_SYNC);

        if (!$this->is_auto_sync_enabled()) {
            return;
        }

        if (!wp_next_scheduled(self::CRON_HOOK_SYNC)) {
            wp_schedule_event(time() + 30, 'stack2_custom', self::CRON_HOOK_SYNC, array(0, 'cron'));
        }
    }

    public function schedule_single_sync(int $delay_seconds, string $trigger, int $attempt): void
    {
        wp_schedule_single_event(time() + max(1, $delay_seconds), self::CRON_HOOK_SYNC, array($attempt, $trigger));
    }

    public function handle_scheduled_sync(int $attempt = 0, string $trigger = 'cron'): void
    {
        $this->sync_inventory($trigger, $attempt);
    }

    public function sync_inventory(string $trigger = 'manual', int $attempt = 0): array
    {
        if (!$this->has_valid_credentials()) {
            $error = 'Missing Stack2 credentials.';
            $this->record_sync_status(false, $error);
            return array('success' => false, 'error' => $error);
        }

        if (get_transient(self::LOCK_TRANSIENT)) {
            $this->logger->debug('Sync skipped due to lock.', array('trigger' => $trigger));
            return array('success' => false, 'error' => 'Sync already in progress.');
        }

        set_transient(self::LOCK_TRANSIENT, '1', 55);

        try {
            $inventory = $this->inventory_collector->collect($this->get_site_id());
            $result = $this->http_client->push_inventory(
                $inventory,
                $this->get_base_url(),
                $this->get_site_id(),
                $this->get_api_key()
            );

            if (!empty($result['success'])) {
                $this->record_sync_status(true, '');
                $this->logger->info('Inventory sync successful.', array('trigger' => $trigger, 'attempt' => $attempt));
                return array('success' => true, 'error' => null);
            }

            $error = (string) ($result['error'] ?? 'Unknown sync error');
            $this->record_sync_status(false, $error);
            $this->logger->error('Inventory sync failed.', array(
                'trigger' => $trigger,
                'attempt' => $attempt,
                'status' => $result['status'] ?? 0,
                'error' => $error,
            ));

            if (!empty($result['retryable'])) {
                $delays = array(5, 30, 120);
                if ($attempt < count($delays)) {
                    $this->schedule_single_sync($delays[$attempt], 'retry', $attempt + 1);
                }
            }

            return array('success' => false, 'error' => $error);
        } finally {
            delete_transient(self::LOCK_TRANSIENT);
        }
    }

    public function has_valid_credentials(): bool
    {
        return $this->get_base_url() !== '' && $this->get_site_id() !== '' && $this->get_api_key() !== '';
    }

    public function get_backup_manager(): Stack2_Backup_Manager
    {
        return $this->backup_manager;
    }

    public function get_update_checker(): Stack2_Update_Checker
    {
        return $this->update_checker;
    }

    private function record_sync_status(bool $success, string $error): void
    {
        update_option(self::OPTION_LAST_SYNC_AT, gmdate('c'));
        update_option(self::OPTION_LAST_SYNC_STATUS, $success ? 'success' : 'failed');
        update_option(self::OPTION_LAST_SYNC_ERROR, $success ? '' : sanitize_text_field($error));
    }

    private function is_auto_sync_enabled(): bool
    {
        return (bool) get_option(self::OPTION_AUTO_SYNC_ENABLED, true);
    }

    private function get_sync_interval_minutes(): int
    {
        $minutes = (int) get_option(self::OPTION_SYNC_INTERVAL_MINUTES, 60);
        if ($minutes < 5) {
            $minutes = 5;
        }

        return $minutes;
    }

    private function get_base_url(): string
    {
        return (string) get_option(self::OPTION_BASE_URL, '');
    }

    private function get_site_id(): string
    {
        return (string) get_option(self::OPTION_SITE_ID, '');
    }

    private function get_api_key(): string
    {
        return (string) get_option(self::OPTION_API_KEY, '');
    }

}
