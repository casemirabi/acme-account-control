<?php
if (!defined('ABSPATH')) {
    exit;
}

add_filter('authenticate', 'acme_users_guard_authenticate_active_account', 30, 1);
add_action('init', 'acme_users_guard_kick_inactive_session');

if (!function_exists('acme_users_guard_authenticate_active_account')) {
    function acme_users_guard_authenticate_active_account($user)
    {
        if ($user instanceof WP_Error || !$user) {
            return $user;
        }

        if (user_can($user, 'administrator')) {
            return $user;
        }

        if (acme_users_repo_get_status((int) $user->ID) === 'inactive') {
            return new WP_Error('inactive', 'Conta inativa.');
        }

        return $user;
    }
}

if (!function_exists('acme_users_guard_kick_inactive_session')) {
    function acme_users_guard_kick_inactive_session()
    {
        if (!is_user_logged_in()) {
            return;
        }

        $uid = get_current_user_id();
        if (user_can($uid, 'administrator')) {
            return;
        }

        if (acme_users_repo_get_status($uid) !== 'inactive') {
            return;
        }

        wp_logout();
        wp_clear_auth_cookie();
        wp_redirect(wp_login_url());
        exit;
    }
}
