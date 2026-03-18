<?php

if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('acme_account_is_active')) {
    function acme_account_is_active(int $user_id): bool
    {
        global $wpdb;
        $statusT = acme_table_status();

        $st = $wpdb->get_var($wpdb->prepare(
            "SELECT status
         FROM {$statusT}
        WHERE user_id=%d
        LIMIT 1",
            $user_id
        ));

        // Se não tiver linha no status, consideramos ativo (mesma lógica do COALESCE em consultas)
        if (!$st)
            return true;

        return $st === 'active';
    }
}

if (!function_exists('acme_get_master_id_of_grandchild')) {
    function acme_get_master_id_of_grandchild(int $grandchild_id): int
    {
        global $wpdb;
        $linksT = acme_table_links();

        $mid = $wpdb->get_var($wpdb->prepare(
            "SELECT parent_user_id
         FROM {$linksT}
        WHERE child_user_id=%d
          AND depth=2
        LIMIT 1",
            $grandchild_id
        ));

        return (int) ($mid ?: 0);
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