<?php
if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('acme_users_registration_handle_create_user')) {
    function acme_users_registration_handle_create_user()
    {
        if (!is_user_logged_in()) {
            wp_die('Você precisa estar logado.');
        }

        if (!isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], 'acme_fe_create_user')) {
            wp_die('Nonce inválido.');
        }

        $actor_id = get_current_user_id();
        $actor = wp_get_current_user();
        $is_admin = user_can($actor_id, 'administrator');
        $is_child = in_array('child', (array) $actor->roles, true);
        $redirect_base = wp_get_referer() ?: site_url('/add-user/');

        if (!$is_admin && !$is_child) {
            wp_die('Sem permissão.');
        }

        $role = isset($_POST['role']) ? sanitize_text_field($_POST['role']) : '';
        $display_name = isset($_POST['display_name']) ? sanitize_text_field($_POST['display_name']) : '';
        $email = isset($_POST['email']) ? sanitize_email($_POST['email']) : '';
        $password = isset($_POST['password']) ? (string) $_POST['password'] : '';
        $phone = isset($_POST['phone']) ? acme_sanitize_phone((string) $_POST['phone']) : '';

        if ($is_child && $role !== 'grandchild') {
            wp_safe_redirect(add_query_arg('acme_msg', 'error', $redirect_base));
            exit;
        }

        if ($is_admin && !in_array($role, ['child', 'grandchild'], true)) {
            wp_safe_redirect(add_query_arg('acme_msg', 'error', $redirect_base));
            exit;
        }

        if (!$display_name || !$email || strlen(trim($password)) < 8) {
            wp_safe_redirect(add_query_arg('acme_msg', 'error', $redirect_base));
            exit;
        }

        $parent_child_id = 0;
        if ($role === 'grandchild') {
            if ($is_admin) {
                $parent_child_id = isset($_POST['parent_child_id']) ? (int) $_POST['parent_child_id'] : 0;
                if ($parent_child_id <= 0) {
                    wp_safe_redirect(add_query_arg('acme_msg', 'missing_parent', $redirect_base));
                    exit;
                }

                $parentChildUser = get_user_by('id', $parent_child_id);
                if (!$parentChildUser || !acme_user_has_role($parentChildUser, 'child') || !acme_account_is_active($parent_child_id)) {
                    wp_safe_redirect(add_query_arg('acme_msg', 'parent_inactive', $redirect_base));
                    exit;
                }
            } else {
                if (!acme_account_is_active($actor_id)) {
                    wp_safe_redirect(add_query_arg('acme_msg', 'parent_inactive', $redirect_base));
                    exit;
                }
                $parent_child_id = $actor_id;
            }
        }

        $base_login = sanitize_user(current(explode('@', $email)), true);
        if (!$base_login) {
            $base_login = 'user';
        }

        $user_login = $base_login;
        $i = 1;
        while (username_exists($user_login)) {
            $user_login = $base_login . $i;
            $i++;
        }

        $user_id = wp_create_user($user_login, $password, $email);
        if (is_wp_error($user_id)) {
            wp_safe_redirect(add_query_arg('acme_msg', 'error', $redirect_base));
            exit;
        }

        wp_update_user([
            'ID' => $user_id,
            'display_name' => $display_name,
            'role' => $role,
        ]);

        if ($phone) {
            acme_users_repo_set_phone((int) $user_id, $phone);
        }

        $admin_master = acme_master_admin_id();

        if ($role === 'child') {
            acme_users_repo_insert_link((int) $admin_master, (int) $user_id, 1);
        }

        if ($role === 'grandchild') {
            if ($parent_child_id <= 0) {
                wp_delete_user($user_id);
                wp_safe_redirect(add_query_arg('acme_msg', 'error', $redirect_base));
                exit;
            }

            acme_users_repo_insert_link((int) $parent_child_id, (int) $user_id, 2);
        }

        wp_safe_redirect(add_query_arg('acme_msg', 'created', site_url('/add-user/')));
        exit;
    }
}
