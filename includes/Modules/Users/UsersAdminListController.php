<?php
if (!defined('ABSPATH')) {
    exit;
}

add_filter('user_row_actions', 'acme_users_admin_add_deactivate_link', 10, 2);
add_filter('manage_users_columns', 'acme_users_admin_add_status_column', 20);
add_filter('manage_users_custom_column', 'acme_users_admin_render_status_column', 20, 3);
add_filter('user_row_actions', 'acme_users_admin_add_activate_link', 11, 2);
add_action('admin_post_acme_deactivate_user', 'acme_users_admin_handle_deactivate');
add_action('admin_post_acme_activate_user', 'acme_users_admin_handle_activate');

if (!function_exists('acme_users_admin_add_deactivate_link')) {
    function acme_users_admin_add_deactivate_link($actions, $user)
    {
        if (user_can($user, 'administrator')) {
            return $actions;
        }

        if (in_array('child', (array) $user->roles, true) || in_array('grandchild', (array) $user->roles, true)) {
            $url = wp_nonce_url(
                admin_url('admin-post.php?action=acme_deactivate_user&user_id=' . (int) $user->ID),
                'acme_deactivate_' . (int) $user->ID
            );

            $actions['acme_deactivate'] = '<a style="color:red" href="' . esc_url($url) . '">Inativar conta</a>';
        }

        return $actions;
    }
}

if (!function_exists('acme_users_admin_handle_deactivate')) {
    function acme_users_admin_handle_deactivate()
    {
        $user_id = isset($_GET['user_id']) ? (int) $_GET['user_id'] : 0;
        check_admin_referer('acme_deactivate_' . $user_id);

        if (!$user_id || !current_user_can('manage_options')) {
            wp_die('Sem permissão.');
        }

        acme_deactivate_tree($user_id);
        wp_redirect(admin_url('users.php'));
        exit;
    }
}

if (!function_exists('acme_users_admin_add_status_column')) {
    function acme_users_admin_add_status_column($cols)
    {
        $cols['acme_status'] = 'Status';
        return $cols;
    }
}

if (!function_exists('acme_users_admin_render_status_column')) {
    function acme_users_admin_render_status_column($val, $column, $user_id)
    {
        if ($column !== 'acme_status') {
            return $val;
        }

        if (acme_users_repo_get_status((int) $user_id) === 'inactive') {
            return '<span style="color:red;font-weight:600">Inativo</span>';
        }

        return '<span style="color:green">Ativo</span>';
    }
}

if (!function_exists('acme_users_admin_add_activate_link')) {
    function acme_users_admin_add_activate_link($actions, $user)
    {
        if (user_can($user, 'administrator')) {
            return $actions;
        }

        if (acme_users_repo_get_status((int) $user->ID) === 'inactive') {
            $url = wp_nonce_url(
                admin_url('admin-post.php?action=acme_activate_user&user_id=' . (int) $user->ID),
                'acme_activate_' . (int) $user->ID
            );

            $actions['acme_activate'] = '<a style="color:green" href="' . esc_url($url) . '">Reativar</a>';
        }

        return $actions;
    }
}

if (!function_exists('acme_users_admin_handle_activate')) {
    function acme_users_admin_handle_activate()
    {
        $targetUserId = isset($_GET['user_id']) ? (int) $_GET['user_id'] : 0;
        if (!$targetUserId) {
            wp_die('Usuário inválido.');
        }

        check_admin_referer('acme_activate_' . $targetUserId);

        if (!current_user_can('manage_options')) {
            wp_die('Sem permissão.');
        }

        if (!function_exists('acme_users_set_status')) {
            wp_die('Service de status não carregado.');
        }

        $targetUser = get_user_by('id', $targetUserId);
        if ($targetUser && acme_user_has_role($targetUser, 'grandchild')) {
            $masterUserId = acme_get_master_id_of_grandchild($targetUserId);
            if ($masterUserId <= 0 || !acme_account_is_active($masterUserId)) {
                wp_die('Não é possível ativar este Sub-Login porque o Master está inativo (ou vínculo ausente).');
            }
        }

        $updated = acme_users_set_status(
            $targetUserId,
            'active',
            null,
            null,
            null,
            [
                'mode' => 'update_only',
            ]
        );

        if (!$updated) {
            wp_die('Falha ao ativar usuário.');
        }

        wp_safe_redirect(admin_url('users.php'));
        exit;
    }
}
