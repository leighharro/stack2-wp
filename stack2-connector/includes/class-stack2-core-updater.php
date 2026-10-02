<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Pins a WordPress core install to one numeric release.
 *
 * The package URL is built here. This class does not call version-check
 * or get_core_updates(), so the newest offer cannot replace the pin.
 * Core_Upgrader selects packages->partial, then new_bundled, then
 * no_content, and only then full. Those other package fields are empty
 * so the upgrader downloads the full wordpress-<version>.zip.
 */
class Stack2_Core_Updater
{
    private const PACKAGE_HOSTS = array(
        'downloads.wordpress.org',
        'downloads.w.org',
    );

    /**
     * X.Y or X.Y.Z. Rejects beta, RC, nightly, and any other suffix.
     */
    public static function is_pinned_release(string $version): bool
    {
        $version = trim($version);
        if ($version === '') {
            return false;
        }

        return (bool) preg_match('/^(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)(?:\.(?:0|[1-9]\d*))?$/', $version);
    }

    /**
     * @return array{result: mixed, skin_messages: array<int, string>}
     */
    public function upgrade(string $version): array
    {
        if (!self::is_pinned_release($version)) {
            return array(
                'result' => new WP_Error('invalid_version', 'Core update requires a pinned numeric release (X.Y or X.Y.Z).'),
                'skin_messages' => array(),
            );
        }

        if (!$this->ensure_core_upgrader_loaded()) {
            return array(
                'result' => new WP_Error('core_upgrader_unavailable', 'WordPress Core_Upgrader is not available.'),
                'skin_messages' => array(),
            );
        }

        $english = $this->package_url($version, 'en_US');
        $locale = $this->site_locale();
        $package_url = $english;
        if ($locale !== 'en_US') {
            $localized = $this->package_url($version, $locale);
            $status = $this->probe_status($localized);
            if ($status !== 404 && $status !== 410) {
                $package_url = $localized;
            }
        }

        if (!$this->package_url_is_allowed($package_url) || !$this->package_url_is_allowed($english)) {
            return array(
                'result' => new WP_Error('invalid_package', 'Core package URL is not an allowed HTTPS download.'),
                'skin_messages' => array(),
            );
        }

        $attempt = $this->invoke($version, $package_url);
        if ($package_url !== $english && $this->missing_package($attempt['result']) && $this->package_url_is_allowed($english)) {
            $attempt = $this->invoke($version, $english);
        }

        return $attempt;
    }

    /**
     * Full zip only. en_US has no locale directory. Other locales use
     * /release/<locale>/wordpress-<version>.zip, which is the layout
     * downloads.wordpress.org serves (404 when that locale has no package).
     */
    public function package_url(string $version, string $locale): string
    {
        if ($locale === 'en_US') {
            $path = 'release/wordpress-' . $version . '.zip';
        } else {
            $path = 'release/' . $locale . '/wordpress-' . $version . '.zip';
        }

        return 'https://downloads.wordpress.org/' . $path;
    }

    /**
     * @return object
     */
    public function offer(string $version, string $package_url): object
    {
        return (object) array(
            'response' => 'upgrade',
            'download' => $package_url,
            'locale' => $this->site_locale(),
            'version' => $version,
            'current' => $version,
            'partial_version' => '',
            'new_bundled' => '',
            'packages' => (object) array(
                'full' => $package_url,
                'no_content' => '',
                'new_bundled' => '',
                'partial' => '',
                'rollback' => '',
            ),
        );
    }

    private function site_locale(): string
    {
        if (!function_exists('get_locale')) {
            return 'en_US';
        }

        $locale = get_locale();
        if (!is_string($locale)) {
            return 'en_US';
        }

        $locale = trim($locale);
        if ($locale === '' || strcasecmp($locale, 'en_US') === 0) {
            return 'en_US';
        }

        if (!preg_match('/^[A-Za-z]{2,3}(?:_[A-Za-z0-9]{2,12}){0,2}$/', $locale)) {
            return 'en_US';
        }

        return $locale;
    }

    private function probe_status(string $url): int
    {
        if (!$this->package_url_is_allowed($url) || !function_exists('wp_remote_head')) {
            return 0;
        }

        $response = wp_remote_head($url, array(
            'timeout' => 15,
            'redirection' => 3,
        ));
        if (is_wp_error($response)) {
            return 0;
        }

        return (int) wp_remote_retrieve_response_code($response);
    }

    /**
     * @param mixed $result
     */
    private function missing_package($result): bool
    {
        if (!is_wp_error($result)) {
            return false;
        }

        $code = (string) $result->get_error_code();
        if (in_array($code, array('download_failed', 'http_404', 'http_request_failed', 'no_package'), true)) {
            return true;
        }

        return str_contains(strtolower((string) $result->get_error_message()), '404');
    }

    /**
     * @return array{result: mixed, skin_messages: array<int, string>}
     */
    private function invoke(string $version, string $package_url): array
    {
        $skin = new Automatic_Upgrader_Skin();
        $upgrader = new Core_Upgrader($skin);
        $result = $upgrader->upgrade($this->offer($version, $package_url), array(
            'pre_check_md5' => false,
            'attempt_rollback' => false,
            'do_rollback' => false,
        ));
        $message_skin = (isset($upgrader->skin) && is_object($upgrader->skin)) ? $upgrader->skin : $skin;

        return array(
            'result' => $result,
            'skin_messages' => $this->skin_messages($message_skin),
        );
    }

    private function package_url_is_allowed(string $url): bool
    {
        $parts = parse_url($url);
        if (!is_array($parts)) {
            return false;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        if ($scheme !== 'https' || !in_array($host, self::PACKAGE_HOSTS, true)) {
            return false;
        }

        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])) {
            return false;
        }

        $path = (string) ($parts['path'] ?? '');
        if ($path === '' || str_contains($path, '..')) {
            return false;
        }

        return !isset($parts['query']) && !isset($parts['fragment']);
    }

    /**
     * @return array<int, string>
     */
    private function skin_messages(object $skin): array
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

    private function ensure_core_upgrader_loaded(): bool
    {
        if (class_exists('Core_Upgrader') && class_exists('Automatic_Upgrader_Skin')) {
            return true;
        }

        foreach (array(
            'wp-admin/includes/file.php',
            'wp-admin/includes/misc.php',
            'wp-admin/includes/class-wp-upgrader.php',
            'wp-admin/includes/class-core-upgrader.php',
        ) as $relative) {
            $path = ABSPATH . $relative;
            if (is_readable($path)) {
                require_once $path;
            }
        }

        return class_exists('Core_Upgrader') && class_exists('Automatic_Upgrader_Skin');
    }
}
