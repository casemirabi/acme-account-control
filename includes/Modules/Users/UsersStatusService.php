<?php
if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('acme_users_can_manage_target')) {
    function acme_users_can_manage_target(int $actorId, int $targetId): bool
    {
        if ($actorId <= 0 || $targetId <= 0) {
            return false;
        }

        if (user_can($actorId, 'administrator')) {
            return true;
        }

        $actor = get_user_by('id', $actorId);
        if (!$actor || !acme_user_has_role($actor, 'child')) {
            return false;
        }

        return acme_users_repo_child_manages_target($actorId, $targetId);
    }
}

if (!function_exists('acme_users_set_status')) {
    function acme_users_set_status(
        int $targetUserId,
        string $status,
        ?int $disabledBy = null,
        ?string $reason = null,
        ?string $disabledAt = null,
        array $options = []
    ): bool {

        if ($targetUserId <= 0) {
            return false;
        }

        $mode = isset($options['mode']) ? (string) $options['mode'] : 'replace';

        return acme_users_repo_set_status(
            $targetUserId,
            $status,
            $disabledBy,
            $reason,
            $disabledAt,
            $mode
        );
    }
}

if (!function_exists('acme_users_deactivate_children_cascade')) {
    function acme_users_deactivate_children_cascade(int $parentUserId, int $actorId, string $reason = 'Cascata (Front-end)'): int
    {
        if ($parentUserId <= 0) {
            return 0;
        }

        global $wpdb;
        $linksTable = acme_table_links();

        $grandchildrenIds = $wpdb->get_col($wpdb->prepare(
            "SELECT child_user_id
             FROM {$linksTable}
             WHERE parent_user_id = %d
               AND depth = 2",
            $parentUserId
        ));

        if (empty($grandchildrenIds)) {
            return 0;
        }

        $updatedCount = 0;
        $disabledAt = current_time('mysql');

        foreach ((array) $grandchildrenIds as $grandchildId) {
            $grandchildId = (int) $grandchildId;

            if ($grandchildId === acme_master_admin_id()) {
                continue;
            }

            if (user_can($grandchildId, 'administrator')) {
                continue;
            }

            $ok = acme_users_set_status(
                $grandchildId,
                'inactive',
                $actorId,
                $reason,
                $disabledAt
            );

            if ($ok) {
                $updatedCount++;
            }
        }

        return $updatedCount;
    }
}

if (!function_exists('acme_users_toggle_status')) {
    function acme_users_toggle_status(int $actorId, int $targetId, string $action): array
    {
        $action = sanitize_key($action);

        if ($actorId <= 0 || $targetId <= 0) {
            return [
                'success' => false,
                'code'    => 'invalid_request',
                'message' => 'Requisição inválida.',
            ];
        }

        if (!in_array($action, ['activate', 'deactivate'], true)) {
            return [
                'success' => false,
                'code'    => 'invalid_action',
                'message' => 'Ação inválida.',
            ];
        }

        $targetUser = get_user_by('id', $targetId);
        if (!$targetUser) {
            return [
                'success' => false,
                'code'    => 'target_not_found',
                'message' => 'Usuário inválido.',
            ];
        }

        if ($targetId === acme_master_admin_id() || user_can($targetId, 'administrator')) {
            return [
                'success' => false,
                'code'    => 'protected_user',
                'message' => 'Usuário protegido.',
            ];
        }

        if (!acme_users_can_manage_target($actorId, $targetId)) {
            return [
                'success' => false,
                'code'    => 'forbidden_target',
                'message' => 'Sem permissão para este usuário.',
            ];
        }

        $actorIsAdmin = user_can($actorId, 'administrator');

        if ($action === 'deactivate') {
            $targetIsChild = acme_user_has_role($targetUser, 'child');

            $ok = acme_users_set_status(
                $targetId,
                'inactive',
                $actorId,
                'Front-end'
            );

            if (!$ok) {
                return [
                    'success' => false,
                    'code'    => 'db_error',
                    'message' => 'Falha ao atualizar status.',
                ];
            }

            if ($targetIsChild && $actorIsAdmin) {
                acme_users_deactivate_children_cascade(
                    $targetId,
                    $actorId,
                    'Cascata (Front-end)'
                );
            }

            return [
                'success' => true,
                'code'    => 'deactivated',
                'message' => 'Status atualizado.',
            ];
        }

        $targetIsGrandchild = acme_user_has_role($targetUser, 'grandchild');

        if ($targetIsGrandchild) {
            $masterId = acme_get_master_id_of_grandchild($targetId);

            if ($masterId <= 0) {
                return [
                    'success' => false,
                    'code'    => 'missing_master',
                    'message' => 'Sub-Login sem Master vinculado.',
                ];
            }

            if (!acme_users_repo_is_active($masterId)) {
                $masterUser = get_user_by('id', $masterId);
                $masterName = $masterUser ? $masterUser->display_name : ('#' . $masterId);

                return [
                    'success' => false,
                    'code'    => 'inactive_master',
                    'message' => 'Não é possível ativar este Sub-Login porque o Master está inativo (' . $masterName . ').',
                ];
            }
        }

        $ok = acme_users_set_status($targetId, 'active');

        if (!$ok) {
            return [
                'success' => false,
                'code'    => 'db_error',
                'message' => 'Falha ao atualizar status.',
            ];
        }

        return [
            'success' => true,
            'code'    => 'activated',
            'message' => 'Status atualizado.',
        ];
    }
}