<?php

if (!defined('ABSPATH')) {
    exit;
}

class Stack2_Update_Checker
{
    public const GITHUB_REPO = 'leighharro/stack2-wp';
    public const PLUGIN_SLUG = 'stack2-connector';
    public const CACHE_TRANSIENT = 'stack2_connector_update_cache';
    public const CACHE_TTL = 12 * HOUR_IN_SECONDS;
    public const ERROR_CACHE_TTL = 15 * MINUTE_IN_SECONDS;

    private Stack2_Logger $logger;
    private string $plugin_basename;

    public function __construct(Stack2_Logger $logger)
    {
        $this->logger = $logger;
        $this->plugin_basename = plugin_basename(STACK2_CONNECTOR_PATH . 'stack2-connector.php');
    }

    public function bootstrap(): void
    {
        add_filter('update_plugins_github.com', array($this, 'check_for_update'), 10, 3);
        add_filter('plugins_api', array($this, 'plugin_info'), 10, 3);
        add_action('delete_site_transient_update_plugins', array($this, 'clear_cache'));
        add_filter('upgrader_pre_download', array($this, 'verify_and_download'), 10, 4);
    }

    public function check_for_update($update, array $plugin_data, string $plugin_file)
    {
        if ($plugin_file !== $this->plugin_basename) {
            return $update;
        }

        $release = $this->get_release_info();
        if ($release === null || $release['package'] === '') {
            return $update;
        }

        if (!version_compare($release['version'], STACK2_CONNECTOR_VERSION, '>')) {
            return $update;
        }

        $plugin_headers = get_plugin_data(STACK2_CONNECTOR_PATH . 'stack2-connector.php', false, false);

        return array(
            'id' => 'github.com/' . self::GITHUB_REPO,
            'slug' => 'stack2-connector',
            'plugin' => $this->plugin_basename,
            'version' => $release['version'],
            'new_version' => $release['version'],
            'url' => $release['html_url'],
            'package' => $release['package'],
            'requires' => $plugin_headers['RequiresWP'] ?? '',
            'requires_php' => $plugin_headers['RequiresPHP'] ?? '',
            'tested' => '',
        );
    }

    public function plugin_info($result, string $action, $args)
    {
        if ($action !== 'plugin_information' || empty($args->slug) || $args->slug !== 'stack2-connector') {
            return $result;
        }

        $release = $this->get_release_info();
        if ($release === null) {
            return $result;
        }

        return (object) array(
            'name' => 'Stack2 Connector',
            'slug' => 'stack2-connector',
            'version' => $release['version'],
            'author' => 'Stack2',
            'homepage' => $release['html_url'],
            'download_link' => $release['package'],
            'sections' => array(
                'changelog' => wpautop(esc_html($release['body'])),
            ),
        );
    }

    public function clear_cache(): void
    {
        delete_transient(self::CACHE_TRANSIENT);
    }

    public function force_check(): array
    {
        $this->clear_cache();
        delete_site_transient('update_plugins');

        $release = $this->get_release_info();
        if ($release === null) {
            return array('success' => false, 'error' => 'Unable to reach GitHub for release information.');
        }

        $has_update = version_compare($release['version'], STACK2_CONNECTOR_VERSION, '>');

        return array(
            'success' => true,
            'error' => null,
            'latest_version' => $release['version'],
            'has_update' => $has_update,
        );
    }

    public function get_cached_release_info(): ?array
    {
        $cached = get_transient(self::CACHE_TRANSIENT);

        return is_array($cached) ? $cached : null;
    }

    public function verify_and_download($reply, string $package, $upgrader, $hook_extra)
    {
        if ($reply !== false) {
            return $reply;
        }

        if (empty($hook_extra['plugin']) || $hook_extra['plugin'] !== $this->plugin_basename) {
            return $reply;
        }

        $release = $this->get_release_info();
        if ($release === null || $release['package'] !== $package || $release['checksum_url'] === '') {
            return $reply;
        }

        $expected_hash = $this->fetch_expected_checksum($release['checksum_url']);
        if ($expected_hash === null) {
            $this->logger->error('Stack2 update checksum unavailable; refusing self-update.', array());
            return new WP_Error('stack2_checksum_unavailable', 'Stack2 Connector update checksum could not be retrieved.');
        }

        if (!function_exists('download_url')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }

        $downloaded = download_url($package);
        if (is_wp_error($downloaded)) {
            return $downloaded;
        }

        $actual_hash = hash_file('sha256', $downloaded);
        if (!hash_equals(strtolower($expected_hash), strtolower((string) $actual_hash))) {
            $this->logger->error('Stack2 update checksum mismatch; refusing self-update.', array(
                'expected' => $expected_hash,
                'actual' => $actual_hash,
            ));
            @unlink($downloaded);
            return new WP_Error('stack2_checksum_mismatch', 'Stack2 Connector update package failed checksum verification.');
        }

        return $downloaded;
    }

    private function get_release_info(): ?array
    {
        $cached = get_transient(self::CACHE_TRANSIENT);
        if (is_array($cached)) {
            return $cached;
        }

        $response = wp_remote_get('https://api.github.com/repos/' . self::GITHUB_REPO . '/releases/latest', array(
            'timeout' => 15,
            'headers' => array(
                'Accept' => 'application/vnd.github+json',
                'User-Agent' => 'Stack2-Connector-WordPress-Plugin',
            ),
        ));

        if (is_wp_error($response)) {
            $this->logger->error('Stack2 update check failed.', array('error' => $response->get_error_message()));
            return null;
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        if ($status !== 200) {
            $this->logger->error('Stack2 update check received unexpected status.', array('status' => $status));
            return null;
        }

        $data = json_decode((string) wp_remote_retrieve_body($response), true);
        if (!is_array($data) || empty($data['tag_name'])) {
            $this->logger->error('Stack2 update check received malformed response.', array());
            return null;
        }

        $version = ltrim((string) $data['tag_name'], 'vV');
        $package = '';
        $checksum_url = '';

        // The release also publishes a stable-named alias (self::PLUGIN_SLUG . '.zip')
        // for `wp plugin install .../releases/latest/download/...zip`; ignore it here
        // and match the version-specific asset so self-update always fetches the
        // exact tagged release regardless of asset ordering.
        $expected_name = self::PLUGIN_SLUG . '-' . $version . '.zip';

        foreach ((array) ($data['assets'] ?? array()) as $asset) {
            $name = (string) ($asset['name'] ?? '');
            $url = (string) ($asset['browser_download_url'] ?? '');

            if (strcasecmp($name, $expected_name) === 0) {
                $package = $url;
            } elseif (strcasecmp($name, $expected_name . '.sha256') === 0) {
                $checksum_url = $url;
            }
        }

        $release = array(
            'version' => $version,
            'html_url' => (string) ($data['html_url'] ?? ''),
            'body' => (string) ($data['body'] ?? ''),
            'package' => $package,
            'checksum_url' => $checksum_url,
            'checked_at' => time(),
        );

        set_transient(self::CACHE_TRANSIENT, $release, $package === '' ? self::ERROR_CACHE_TTL : self::CACHE_TTL);

        return $release;
    }

    private function fetch_expected_checksum(string $checksum_url): ?string
    {
        $response = wp_remote_get($checksum_url, array(
            'timeout' => 15,
            'headers' => array('User-Agent' => 'Stack2-Connector-WordPress-Plugin'),
        ));

        if (is_wp_error($response) || (int) wp_remote_retrieve_response_code($response) !== 200) {
            return null;
        }

        $body = trim((string) wp_remote_retrieve_body($response));
        if (preg_match('/^([a-f0-9]{64})/i', $body, $matches)) {
            return $matches[1];
        }

        return null;
    }
}
