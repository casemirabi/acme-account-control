<?php

if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('acme_account_is_active')) {
    function acme_account_is_active(int $user_id): bool
    {
        return acme_users_repo_is_active($user_id);
    }
}

if (!function_exists('acme_get_master_id_of_grandchild')) {
    function acme_get_master_id_of_grandchild(int $grandchild_id): int
    {
        return acme_users_repo_get_master_id_of_grandchild($grandchild_id);
    }
}

function acme_deactivate_tree($user_id)
{
    $targetUserId = (int) $user_id;

    // Admin master nunca inativo
    if ($targetUserId === (int) acme_master_admin_id()) {
        return;
    }

    if (!function_exists('acme_users_set_status')) {
        return;
    }

    $disabledAt = current_time('mysql');

    $targetUser = get_user_by('id', $targetUserId);
    $isChild = $targetUser && acme_user_has_role($targetUser, 'child');

    // ✅ inativa alvo
    acme_users_set_status(
        $targetUserId,
        'inactive',
        null,          // disabled_by (não existia antes)
        null,          // reason (não existia antes)
        $disabledAt,
        [
            'mode' => 'replace',
        ]
    );

    // ✅ cascata se for child
    if ($isChild && function_exists('acme_users_deactivate_children_cascade')) {

        acme_users_deactivate_children_cascade(
            $targetUserId,
            0,                  // disabled_by
            'Cascata (tree)'    // reason
        );
    }
}