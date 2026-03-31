<?php
if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('acme_users_repo_get_phone')) {
    function acme_users_repo_get_phone(int $userId): string
    {
        if ($userId <= 0) {
            return '';
        }

        return (string) get_user_meta($userId, 'phone', true);
    }
}

if (!function_exists('acme_users_repo_set_phone')) {
    function acme_users_repo_set_phone(int $userId, string $phone): bool
    {
        if ($userId <= 0) {
            return false;
        }

        $result = update_user_meta($userId, 'phone', $phone);
        return $result !== false;
    }
}

if (!function_exists('acme_users_repo_get_status')) {
    function acme_users_repo_get_status(int $userId): string
    {
        if ($userId <= 0) {
            return 'active';
        }

        global $wpdb;
        $statusTable = acme_table_status();

        $status = $wpdb->get_var($wpdb->prepare(
            "SELECT status FROM {$statusTable} WHERE user_id = %d LIMIT 1",
            $userId
        ));

        return $status ? (string) $status : 'active';
    }
}

if (!function_exists('acme_users_repo_is_active')) {
    function acme_users_repo_is_active(int $userId): bool
    {
        return acme_users_repo_get_status($userId) !== 'inactive';
    }
}

if (!function_exists('acme_users_repo_set_status')) {
    function acme_users_repo_set_status(
        int $targetUserId,
        string $status,
        ?int $disabledBy = null,
        ?string $reason = null,
        ?string $disabledAt = null,
        string $mode = 'replace'
    ): bool {
        if ($targetUserId <= 0) {
            return false;
        }

        global $wpdb;
        $statusTable = acme_table_status();

        $data = [
            'status'      => $status,
            'disabled_at' => $status === 'inactive' ? ($disabledAt ?: current_time('mysql')) : null,
            'disabled_by' => $status === 'inactive' ? $disabledBy : null,
            'reason'      => $status === 'inactive' ? $reason : null,
        ];

        if ($mode === 'update_only') {
            $result = $wpdb->update($statusTable, $data, ['user_id' => $targetUserId]);
            return $result !== false;
        }

        if ($mode === 'replace') {
            $result = $wpdb->replace($statusTable, array_merge(['user_id' => $targetUserId], $data));
            return $result !== false;
        }

        return false;
    }
}

if (!function_exists('acme_users_repo_get_master_id_of_grandchild')) {
    function acme_users_repo_get_master_id_of_grandchild(int $grandchildId): int
    {
        if ($grandchildId <= 0) {
            return 0;
        }

        global $wpdb;
        $linksTable = acme_table_links();

        $masterId = $wpdb->get_var($wpdb->prepare(
            "SELECT parent_user_id FROM {$linksTable} WHERE child_user_id = %d AND depth = 2 LIMIT 1",
            $grandchildId
        ));

        return (int) ($masterId ?: 0);
    }
}

if (!function_exists('acme_users_repo_child_manages_target')) {
    function acme_users_repo_child_manages_target(int $childId, int $targetId): bool
    {
        if ($childId <= 0 || $targetId <= 0) {
            return false;
        }

        global $wpdb;
        $linksTable = acme_table_links();

        $linkId = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$linksTable} WHERE parent_user_id = %d AND child_user_id = %d AND depth = 2 LIMIT 1",
            $childId,
            $targetId
        ));

        return !empty($linkId);
    }
}

if (!function_exists('acme_users_repo_delete_depth_link')) {
    function acme_users_repo_delete_depth_link(int $childUserId, int $depth): bool
    {
        if ($childUserId <= 0 || $depth <= 0) {
            return false;
        }

        global $wpdb;
        $linksTable = acme_table_links();
        $result = $wpdb->delete($linksTable, ['child_user_id' => $childUserId, 'depth' => $depth]);
        return $result !== false;
    }
}

if (!function_exists('acme_users_repo_insert_link')) {
    function acme_users_repo_insert_link(int $parentUserId, int $childUserId, int $depth): bool
    {
        if ($parentUserId <= 0 || $childUserId <= 0 || $depth <= 0) {
            return false;
        }

        global $wpdb;
        $linksTable = acme_table_links();
        $result = $wpdb->insert($linksTable, [
            'parent_user_id' => $parentUserId,
            'child_user_id'  => $childUserId,
            'depth'          => $depth,
        ]);

        return $result !== false;
    }
}

if (!function_exists('acme_users_repo_replace_grandchild_parent')) {
    function acme_users_repo_replace_grandchild_parent(int $userId, int $parentId): bool
    {
        if ($userId <= 0 || $parentId <= 0) {
            return false;
        }

        acme_users_repo_delete_depth_link($userId, 2);
        return acme_users_repo_insert_link($parentId, $userId, 2);
    }
}

if (!function_exists('acme_users_repo_get_parent_for_grandchild')) {
    function acme_users_repo_get_parent_for_grandchild(int $userId): int
    {
        return acme_users_repo_get_master_id_of_grandchild($userId);
    }
}

if (!function_exists('acme_users_repo_get_children_for_filter')) {
    function acme_users_repo_get_children_for_filter(): array
    {
        return get_users(['role' => 'child']);
    }
}

if (!function_exists('acme_users_repo_get_all_grandchildren_rows')) {
    function acme_users_repo_get_all_grandchildren_rows(): array
    {
        global $wpdb;
        $linksTable = acme_table_links();
        $statusTable = acme_table_status();
        $usersTable = $wpdb->users;

        return (array) $wpdb->get_results(
            "SELECT u.ID, u.display_name, u.user_email,
                    COALESCE(s.status,'active') AS status,
                    s.disabled_at
             FROM {$linksTable} l
             INNER JOIN {$usersTable} u ON u.ID = l.child_user_id
             LEFT JOIN {$statusTable} s ON s.user_id = u.ID
             WHERE l.depth = 2
             ORDER BY u.display_name ASC"
        );
    }
}

if (!function_exists('acme_users_repo_get_grandchildren_rows_by_parent')) {
    function acme_users_repo_get_grandchildren_rows_by_parent(int $parentUserId): array
    {
        if ($parentUserId <= 0) {
            return [];
        }

        global $wpdb;
        $linksTable = acme_table_links();
        $statusTable = acme_table_status();
        $usersTable = $wpdb->users;

        return (array) $wpdb->get_results($wpdb->prepare(
            "SELECT u.ID, u.display_name, u.user_email,
                    COALESCE(s.status,'active') AS status,
                    s.disabled_at,
                    COALESCE(SUM(ct.credits), 0) AS credits
             FROM {$linksTable} l
             INNER JOIN {$usersTable} u ON u.ID = l.child_user_id
             LEFT JOIN {$statusTable} s ON s.user_id = u.ID
             LEFT JOIN wp_credit_transactions ct
                    ON ct.user_id = u.ID
                   AND ct.type = 'credit'
                   AND ct.status = 'success'
             WHERE l.parent_user_id = %d
               AND l.depth = 2
             GROUP BY u.ID, u.display_name, u.user_email, s.status, s.disabled_at
             ORDER BY u.display_name ASC",
            $parentUserId
        ));
    }
}

if (!function_exists('acme_users_repo_get_actor_credit_summary')) {
    function acme_users_repo_get_actor_credit_summary(int $userId): array
    {
        global $wpdb;

        $lotsTable = function_exists('acme_table_credit_lots') ? acme_table_credit_lots() : ($wpdb->prefix . 'credit_lots');
        $servicesTable = function_exists('acme_table_services') ? acme_table_services() : ($wpdb->prefix . 'services');
        $nowMysql = current_time('mysql');

        $lotRows = $wpdb->get_results($wpdb->prepare(
            "SELECT l.service_id,
                    l.credits_total, l.credits_used, l.expires_at,
                    s.slug, s.name
             FROM {$lotsTable} l
             LEFT JOIN {$servicesTable} s ON s.id = l.service_id
             WHERE l.owner_user_id = %d
               AND (l.expires_at IS NULL OR l.expires_at >= %s)
             ORDER BY s.name ASC, l.id ASC",
            $userId,
            $nowMysql
        ));

        $creditTotalAvailable = 0;
        $creditBreakdown = [];

        foreach ((array) $lotRows as $row) {
            $available = max(0, (int) $row->credits_total - (int) $row->credits_used);
            $creditTotalAvailable += $available;
            $serviceId = (int) $row->service_id;

            if (!isset($creditBreakdown[$serviceId])) {
                $creditBreakdown[$serviceId] = [
                    'service'     => $row->name ?: ('Serviço #' . $serviceId),
                    'avail'       => 0,
                    'nearest_exp' => null,
                ];
            }

            $creditBreakdown[$serviceId]['avail'] += $available;

            if (!empty($row->expires_at)) {
                $expiresTs = strtotime($row->expires_at);
                $currentNearestTs = !empty($creditBreakdown[$serviceId]['nearest_exp']) ? strtotime($creditBreakdown[$serviceId]['nearest_exp']) : null;
                if (!$currentNearestTs || ($expiresTs && $expiresTs < $currentNearestTs)) {
                    $creditBreakdown[$serviceId]['nearest_exp'] = $row->expires_at;
                }
            }
        }

        return [
            'credit_total_available' => $creditTotalAvailable,
            'credit_breakdown'       => array_values($creditBreakdown),
        ];
    }
}

if (!function_exists('acme_users_repo_get_manage_base_rows')) {
    function acme_users_repo_get_manage_base_rows(int $actorUserId, bool $isAdmin): array
    {
        global $wpdb;
        $linksTable = acme_table_links();
        $statusTable = acme_table_status();
        $usersTable = $wpdb->users;

        if ($isAdmin) {
            $grandRows = $wpdb->get_results(
                "SELECT u.ID, u.display_name, u.user_email,
                        l.parent_user_id AS master_id,
                        COALESCE(s.status, 'active') AS status,
                        s.disabled_at
                 FROM {$linksTable} l
                 INNER JOIN {$usersTable} u ON u.ID = l.child_user_id
                 LEFT JOIN {$statusTable} s ON s.user_id = u.ID
                 WHERE l.depth = 2
                 ORDER BY u.display_name ASC"
            );
        } else {
            $grandRows = $wpdb->get_results($wpdb->prepare(
                "SELECT u.ID, u.display_name, u.user_email,
                        l.parent_user_id AS master_id,
                        COALESCE(s.status, 'active') AS status,
                        s.disabled_at
                 FROM {$linksTable} l
                 INNER JOIN {$usersTable} u ON u.ID = l.child_user_id
                 LEFT JOIN {$statusTable} s ON s.user_id = u.ID
                 WHERE l.depth = 2
                   AND l.parent_user_id = %d
                 ORDER BY u.display_name ASC",
                $actorUserId
            ));
        }

        $rows = [];
        foreach ((array) $grandRows as $row) {
            $row->phone = acme_users_repo_get_phone((int) $row->ID);
            $row->acme_type = acme_role_label('grandchild');
            $rows[] = $row;
        }

        if ($isAdmin) {
            $childrenUsers = acme_users_repo_get_children_for_filter();
            foreach ((array) $childrenUsers as $childUser) {
                $childId = (int) $childUser->ID;
                $rows[] = (object) [
                    'ID'           => $childId,
                    'display_name' => $childUser->display_name,
                    'user_email'   => $childUser->user_email,
                    'master_id'    => $childId,
                    'status'       => acme_users_repo_get_status($childId),
                    'disabled_at'  => acme_users_repo_get_status($childId) === 'inactive' ? $wpdb->get_var($wpdb->prepare("SELECT disabled_at FROM {$statusTable} WHERE user_id = %d", $childId)) : null,
                    'phone'        => acme_users_repo_get_phone($childId),
                    'acme_type'    => acme_role_label('child'),
                ];
            }
        }

        return $rows;
    }
}

if (!function_exists('acme_users_repo_get_credits_map')) {
    function acme_users_repo_get_credits_map(array $rows): array
    {
        global $wpdb;
        $lotsTable = function_exists('acme_table_credit_lots') ? acme_table_credit_lots() : ($wpdb->prefix . 'credit_lots');
        $nowMysql = current_time('mysql');

        $userIds = array_values(array_unique(array_map(function ($row) {
            return (int) $row->ID;
        }, $rows)));

        if (empty($userIds)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($userIds), '%d'));
        $sql = $wpdb->prepare(
            "SELECT owner_user_id AS user_id,
                    COALESCE(SUM(GREATEST(credits_total - credits_used, 0)), 0) AS available
             FROM {$lotsTable}
             WHERE owner_user_id IN ($placeholders)
               AND (expires_at IS NULL OR expires_at >= %s)
             GROUP BY owner_user_id",
            array_merge($userIds, [$nowMysql])
        );

        $lotSums = $wpdb->get_results($sql);
        $creditsMap = [];
        foreach ((array) $lotSums as $row) {
            $creditsMap[(int) $row->user_id] = (int) $row->available;
        }

        return $creditsMap;
    }
}
