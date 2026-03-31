<?php
if (!defined('ABSPATH')) {
    exit;
}

add_action('admin_post_acme_fe_toggle_status', 'acme_controller_toggle_status');
add_action('admin_post_acme_fe_bulk_activate', 'acme_controller_bulk_activate');
add_action('admin_post_acme_fe_bulk_deactivate', 'acme_controller_bulk_deactivate');
add_action('admin_post_acme_fe_set_password', 'acme_controller_set_password');
add_action('admin_post_acme_fe_update_phone', 'acme_controller_update_phone');
add_action('admin_post_acme_fe_create_user', 'acme_controller_create_user');

function acme_controller_toggle_status() { acme_fe_toggle_status(); }
function acme_controller_bulk_activate() { acme_fe_bulk_activate(); }
function acme_controller_bulk_deactivate() { acme_fe_bulk_deactivate(); }
function acme_controller_set_password() { acme_fe_set_password(); }
function acme_controller_update_phone() { acme_fe_update_phone(); }
function acme_controller_create_user() { acme_users_registration_handle_create_user(); }

if (!function_exists('acme_fe_toggle_status')) {
    function acme_fe_toggle_status()
    {
        if (!is_user_logged_in()) {
            wp_die('Você precisa estar logado.');
        }

        $actorId = get_current_user_id();

        if (!isset($_POST['user_id'], $_POST['_wpnonce'], $_POST['do'])) {
            wp_die('Requisição inválida.');
        }

        $targetId = (int) $_POST['user_id'];
        $action = sanitize_key((string) $_POST['do']);

        if (!wp_verify_nonce($_POST['_wpnonce'], 'acme_fe_toggle_' . $targetId)) {
            wp_die('Nonce inválido.');
        }

        $actor = wp_get_current_user();
        $actorIsAdmin = user_can($actorId, 'administrator');
        $actorIsChild = in_array('child', (array) $actor->roles, true);

        if (!$actorIsAdmin && !$actorIsChild) {
            wp_die('Sem permissão.');
        }

        $result = acme_users_toggle_status($actorId, $targetId, $action);
        $redirectUrl = isset($_POST['redirect_to'])
            ? esc_url_raw(wp_unslash($_POST['redirect_to']))
            : (wp_get_referer() ?: home_url('/'));

        if (!$result['success']) {
            if (in_array($result['code'], ['protected_user'], true)) {
                wp_safe_redirect($redirectUrl);
                exit;
            }

            if (in_array($result['code'], ['missing_master', 'inactive_master'], true)) {
                wp_safe_redirect(add_query_arg([
                    'acme_msg' => 'err_master',
                    'acme_err' => $result['message'],
                ], $redirectUrl));
                exit;
            }

            wp_die($result['message']);
        }

        wp_safe_redirect(add_query_arg('acme_msg', 'ok', $redirectUrl));
        exit;
    }
}

if (!function_exists('acme_fe_bulk_activate')) {
    function acme_fe_bulk_activate()
    {
        if (!is_user_logged_in()) {
            wp_die('Você precisa estar logado.');
        }

        $actorId = get_current_user_id();
        $actor = wp_get_current_user();
        $isAdmin = user_can($actorId, 'administrator');
        $isChild = in_array('child', (array) $actor->roles, true);

        if (!$isAdmin && !$isChild) {
            wp_die('Sem permissão.');
        }

        if (empty($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], 'acme_fe_bulk_activate')) {
            wp_die('Nonce inválido.');
        }

        if (!function_exists('acme_users_toggle_status')) {
            wp_die('Service de status não carregado.');
        }

        $selectedIds = isset($_POST['user_ids']) ? (array) $_POST['user_ids'] : [];
        $scopeIds = isset($_POST['scope_ids']) ? (array) $_POST['scope_ids'] : [];
        $doAll = !empty($_POST['bulk_all']);
        $ids = $doAll ? $scopeIds : $selectedIds;
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        $redirectUrl = wp_get_referer() ?: home_url('/');

        if (empty($ids)) {
            wp_safe_redirect(add_query_arg('acme_msg', 'bulk_none', $redirectUrl));
            exit;
        }

        if (!$isAdmin) {
            $ids = array_values(array_filter($ids, function ($userId) use ($actorId) {
                return acme_users_repo_child_manages_target((int) $actorId, (int) $userId);
            }));

            if (empty($ids)) {
                wp_safe_redirect(add_query_arg('acme_msg', 'bulk_none', $redirectUrl));
                exit;
            }
        }

        $safeIds = [];
        foreach ($ids as $userId) {
            $userId = (int) $userId;
            if ($userId === (int) acme_master_admin_id()) {
                continue;
            }
            if (user_can($userId, 'administrator')) {
                continue;
            }
            $safeIds[] = $userId;
        }

        if (empty($safeIds)) {
            wp_safe_redirect(add_query_arg('acme_msg', 'bulk_none', $redirectUrl));
            exit;
        }

        $activatedCount = 0;
        $skippedMasterInactive = 0;

        foreach ($safeIds as $userId) {
            $result = acme_users_toggle_status($actorId, (int) $userId, 'activate');

            if (!is_array($result) || empty($result['success'])) {
                if (!empty($result['code']) && in_array($result['code'], ['missing_master', 'inactive_master'], true)) {
                    $skippedMasterInactive++;
                }
                continue;
            }

            $activatedCount++;
        }

        if ($activatedCount <= 0) {
            wp_safe_redirect(add_query_arg([
                'acme_msg' => 'bulk_master_block',
                'bulk_skipped' => $skippedMasterInactive,
            ], $redirectUrl));
            exit;
        }

        wp_safe_redirect(add_query_arg([
            'acme_msg' => 'bulk_ok',
            'bulk_count' => $activatedCount,
            'bulk_skipped' => $skippedMasterInactive,
        ], $redirectUrl));
        exit;
    }
}

if (!function_exists('acme_fe_bulk_deactivate')) {
    function acme_fe_bulk_deactivate()
    {
        if (!is_user_logged_in()) {
            wp_die('Você precisa estar logado.');
        }

        $actorId = get_current_user_id();
        $actor = wp_get_current_user();
        $isAdmin = user_can($actorId, 'administrator');
        $isChild = in_array('child', (array) $actor->roles, true);

        if (!$isAdmin && !$isChild) {
            wp_die('Sem permissão.');
        }

        $nonce = $_POST['_wpnonce'] ?? '';
        if (empty($nonce) || (!wp_verify_nonce($nonce, 'acme_fe_bulk_deactivate') && !wp_verify_nonce($nonce, 'acme_fe_bulk_activate'))) {
            wp_die('Nonce inválido.');
        }

        if (!function_exists('acme_users_set_status')) {
            wp_die('Service de status não carregado.');
        }

        $selectedIds = isset($_POST['user_ids']) ? (array) $_POST['user_ids'] : [];
        $scopeIds = isset($_POST['scope_ids']) ? (array) $_POST['scope_ids'] : [];
        $doAll = !empty($_POST['bulk_all']);

        $ids = $doAll ? $scopeIds : $selectedIds;
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        $redirectUrl = wp_get_referer() ?: home_url('/');

        if (empty($ids)) {
            wp_safe_redirect(add_query_arg('acme_msg', 'bulk_none', $redirectUrl));
            exit;
        }

        if (!$isAdmin) {
            $ids = array_values(array_filter($ids, function ($userId) use ($actorId) {
                return acme_users_repo_child_manages_target((int) $actorId, (int) $userId);
            }));

            if (empty($ids)) {
                wp_safe_redirect(add_query_arg('acme_msg', 'bulk_none', $redirectUrl));
                exit;
            }
        }

        $safeIds = [];
        foreach ($ids as $userId) {
            $userId = (int) $userId;
            if ($userId === (int) acme_master_admin_id()) {
                continue;
            }
            if (user_can($userId, 'administrator')) {
                continue;
            }
            $safeIds[] = $userId;
        }

        if (empty($safeIds)) {
            wp_safe_redirect(add_query_arg('acme_msg', 'bulk_none', $redirectUrl));
            exit;
        }

        $disabledAt = current_time('mysql');
        $count = 0;

        foreach ($safeIds as $userId) {
            $updated = acme_users_set_status(
                (int) $userId,
                'inactive',
                $actorId,
                'Bulk (Front-end)',
                $disabledAt,
                ['mode' => 'replace']
            );

            if ($updated) {
                $count++;
            }

            if ($isAdmin) {
                $targetUser = get_user_by('id', (int) $userId);
                $isTargetChild = $targetUser && in_array('child', (array) $targetUser->roles, true);

                if ($isTargetChild && function_exists('acme_users_deactivate_children_cascade')) {
                    $count += (int) acme_users_deactivate_children_cascade(
                        (int) $userId,
                        $actorId,
                        'Cascata (Bulk Front-end)'
                    );
                }
            }
        }

        wp_safe_redirect(add_query_arg([
            'acme_msg' => 'bulk_deact_ok',
            'bulk_count' => $count,
        ], $redirectUrl));
        exit;
    }
}

if (!function_exists('acme_fe_set_password')) {
    function acme_fe_set_password()
    {
        if (!is_user_logged_in()) {
            wp_die('Você precisa estar logado.');
        }

        $actorId = get_current_user_id();

        if (!isset($_POST['user_id'], $_POST['_wpnonce'], $_POST['new_pass'])) {
            wp_die('Requisição inválida.');
        }

        $targetId = (int) $_POST['user_id'];
        $newPass = trim((string) $_POST['new_pass']);

        if (!wp_verify_nonce($_POST['_wpnonce'], 'acme_fe_pass_' . $targetId)) {
            wp_die('Nonce inválido.');
        }

        $actor = wp_get_current_user();
        $isAdmin = user_can($actorId, 'administrator');
        $isChild = in_array('child', (array) $actor->roles, true);

        if (!$isAdmin && !$isChild) {
            wp_die('Sem permissão.');
        }

        if ($targetId === acme_master_admin_id() || user_can($targetId, 'administrator')) {
            wp_safe_redirect(wp_get_referer() ?: home_url('/'));
            exit;
        }

        if (!$isAdmin && !acme_users_repo_child_manages_target((int) $actorId, (int) $targetId)) {
            wp_die('Sem permissão para este usuário.');
        }

        if (strlen($newPass) < 8) {
            wp_die('Senha deve ter no mínimo 8 caracteres.');
        }

        wp_set_password($newPass, $targetId);

        if (class_exists('WP_Session_Tokens')) {
            $tokens = WP_Session_Tokens::get_instance($targetId);
            $tokens->destroy_all();
        }

        wp_safe_redirect(add_query_arg('acme_msg', 'pass', wp_get_referer() ?: home_url('/')));
        exit;
    }
}

if (!function_exists('acme_fe_update_phone')) {
    function acme_fe_update_phone()
    {
        if (!is_user_logged_in()) {
            wp_die('Você precisa estar logado.');
        }

        $actorId = get_current_user_id();

        if (!isset($_POST['user_id'], $_POST['_wpnonce'], $_POST['phone'])) {
            wp_die('Requisição inválida.');
        }

        $targetId = (int) $_POST['user_id'];

        if (!wp_verify_nonce($_POST['_wpnonce'], 'acme_fe_phone_' . $targetId)) {
            wp_die('Nonce inválido.');
        }

        $actor = wp_get_current_user();
        $isAdmin = user_can($actorId, 'administrator');
        $isChild = in_array('child', (array) $actor->roles, true);

        if (!$isAdmin && !$isChild) {
            wp_die('Sem permissão.');
        }

        if ($targetId === acme_master_admin_id() || user_can($targetId, 'administrator')) {
            wp_safe_redirect(wp_get_referer() ?: home_url('/'));
            exit;
        }

        if (!$isAdmin && !acme_users_repo_child_manages_target((int) $actorId, (int) $targetId)) {
            wp_die('Sem permissão para este usuário.');
        }

        $phone = acme_sanitize_phone((string) $_POST['phone']);
        acme_users_repo_set_phone($targetId, $phone);

        wp_safe_redirect(add_query_arg('acme_msg', 'phone', wp_get_referer() ?: home_url('/')));
        exit;
    }
}
