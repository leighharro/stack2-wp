<?php

if (!defined('ABSPATH')) {
    exit;
}

class Stack2_SSO_Service
{
    private const TOKEN_TTL_SECONDS = 60;
    private const TRANSIENT_PREFIX = 'stack2_sso_';

    private Stack2_Logger $logger;

    public function __construct(Stack2_Logger $logger)
    {
        $this->logger = $logger;
    }

    public function list_admin_users(): array
    {
        $users = get_users(array(
            'role' => 'administrator',
            'orderby' => 'display_name',
        ));

        return array_map(function ($user) {
            return array(
                'id' => $user->ID,
                'login' => $user->user_login,
                'display_name' => $user->display_name,
                'email' => $user->user_email,
            );
        }, $users);
    }

    public function create_login_token(int $user_id): ?string
    {
        if (!$this->user_is_eligible($user_id)) {
            return null;
        }

        $token = bin2hex(random_bytes(32));
        set_transient($this->transient_key($token), $user_id, self::TOKEN_TTL_SECONDS);

        return $token;
    }

    public function consume_login_token(string $token): ?int
    {
        $key = $this->transient_key($token);
        $user_id = get_transient($key);
        delete_transient($key);

        if ($user_id === false) {
            return null;
        }

        $user_id = (int) $user_id;

        return $this->user_is_eligible($user_id) ? $user_id : null;
    }

    public function maybe_complete_login(): void
    {
        if (empty($_GET['stack2_sso_token'])) {
            return;
        }

        $token = sanitize_text_field(wp_unslash($_GET['stack2_sso_token']));
        $user_id = $this->consume_login_token($token);

        if ($user_id === null) {
            $this->logger->info('Rejected SSO login attempt with invalid or expired token.');
            wp_safe_redirect(wp_login_url());
            exit;
        }

        wp_set_current_user($user_id);
        wp_set_auth_cookie($user_id, false);

        $user = get_userdata($user_id);
        if ($user) {
            do_action('wp_login', $user->user_login, $user);
        }

        wp_safe_redirect(admin_url());
        exit;
    }

    private function user_is_eligible(int $user_id): bool
    {
        $user = get_userdata($user_id);

        return $user instanceof WP_User && user_can($user, 'manage_options');
    }

    private function transient_key(string $token): string
    {
        return self::TRANSIENT_PREFIX . hash('sha256', $token);
    }
}
