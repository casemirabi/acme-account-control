<?php

if (!defined('ABSPATH')) {
    exit;
}


/**
 * Preparação para acme_my_grandchildren_manage
 */
if (!function_exists('acme_users_manage_get_filters')) {
    function acme_users_manage_get_filters(bool $isAdmin): array
    {
        $query = isset($_GET['q']) ? trim((string) $_GET['q']) : '';
        $queryNormalized = mb_strtolower($query);

        $filterMaster = isset($_GET['master']) ? (int) $_GET['master'] : 0;
        $filterStatus = isset($_GET['status']) ? sanitize_text_field((string) $_GET['status']) : 'all';
        $filterCredits = isset($_GET['credits']) ? sanitize_text_field((string) $_GET['credits']) : 'all';

        if (!$isAdmin) {
            $filterMaster = 0;
        }

        if (!in_array($filterStatus, ['all', 'active', 'inactive'], true)) {
            $filterStatus = 'all';
        }

        if (!in_array($filterCredits, ['all', 'has', 'none'], true)) {
            $filterCredits = 'all';
        }

        return [
            'q'               => $query,
            'q_norm'          => $queryNormalized,
            'filter_master'   => $filterMaster,
            'filter_status'   => $filterStatus,
            'filter_credits'  => $filterCredits,
        ];
    }
}

#===================================
if (!function_exists('acme_users_manage_get_actor_credit_summary')) {
    function acme_users_manage_get_actor_credit_summary(int $userId): array
    {
        global $wpdb;

        $lotsTable = function_exists('acme_table_credit_lots')
            ? acme_table_credit_lots()
            : ($wpdb->prefix . 'credit_lots');

        $servicesTable = function_exists('acme_table_services')
            ? acme_table_services()
            : ($wpdb->prefix . 'services');

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
                $currentNearestTs = !empty($creditBreakdown[$serviceId]['nearest_exp'])
                    ? strtotime($creditBreakdown[$serviceId]['nearest_exp'])
                    : null;

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

#===================================
if (!function_exists('acme_users_manage_get_base_rows')) {
    function acme_users_manage_get_base_rows(int $actorUserId, bool $isAdmin): array
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
            $row->phone = get_user_meta($row->ID, 'phone', true);
            $row->acme_type = acme_role_label('grandchild');
            $rows[] = $row;
        }

        if ($isAdmin) {
            $childrenUsers = get_users(['role' => 'child']);

            foreach ((array) $childrenUsers as $childUser) {
                $childRow = (object) [
                    'ID'           => $childUser->ID,
                    'display_name' => $childUser->display_name,
                    'user_email'   => $childUser->user_email,
                    'master_id'    => (int) $childUser->ID,
                    'status'       => 'active',
                    'disabled_at'  => null,
                    'phone'        => get_user_meta($childUser->ID, 'phone', true),
                    'acme_type'    => acme_role_label('child'),
                ];

                $statusValue = $wpdb->get_var($wpdb->prepare(
                    "SELECT status FROM {$statusTable} WHERE user_id = %d",
                    $childUser->ID
                ));

                if ($statusValue) {
                    $childRow->status = $statusValue;
                }

                $disabledAtValue = $wpdb->get_var($wpdb->prepare(
                    "SELECT disabled_at FROM {$statusTable} WHERE user_id = %d",
                    $childUser->ID
                ));

                if ($disabledAtValue) {
                    $childRow->disabled_at = $disabledAtValue;
                }

                $rows[] = $childRow;
            }
        }

        return $rows;
    }
}

#===================================
if (!function_exists('acme_users_manage_get_credits_map')) {
    function acme_users_manage_get_credits_map(array $rows): array
    {
        global $wpdb;

        $lotsTable = function_exists('acme_table_credit_lots')
            ? acme_table_credit_lots()
            : ($wpdb->prefix . 'credit_lots');

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

#===================================
if (!function_exists('acme_users_manage_filter_rows')) {
    function acme_users_manage_filter_rows(array $rows, array $filters, bool $isAdmin): array
    {
        $filterMaster = (int) ($filters['filter_master'] ?? 0);
        $filterStatus = (string) ($filters['filter_status'] ?? 'all');
        $filterCredits = (string) ($filters['filter_credits'] ?? 'all');
        $queryNormalized = (string) ($filters['q_norm'] ?? '');

        if ($isAdmin && $filterMaster > 0) {
            $rows = array_values(array_filter($rows, function ($row) use ($filterMaster) {
                $isChildRow = ((int) $row->ID === $filterMaster);
                $isGrandchildOfMaster = ((int) ($row->master_id ?? 0) === $filterMaster);

                return $isChildRow || $isGrandchildOfMaster;
            }));
        }

        if ($filterStatus === 'active' || $filterStatus === 'inactive') {
            $rows = array_values(array_filter($rows, function ($row) use ($filterStatus) {
                $status = (string) ($row->status ?? 'active');
                return $status === $filterStatus;
            }));
        }

        if ($queryNormalized !== '') {
            $rows = array_values(array_filter($rows, function ($row) use ($queryNormalized) {
                $name = mb_strtolower((string) ($row->display_name ?? ''));
                $email = mb_strtolower((string) ($row->user_email ?? ''));
                $phone = mb_strtolower((string) ($row->phone ?? ''));

                return strpos($name, $queryNormalized) !== false
                    || strpos($email, $queryNormalized) !== false
                    || strpos($phone, $queryNormalized) !== false;
            }));
        }

        if ($filterCredits === 'has') {
            $rows = array_values(array_filter($rows, function ($row) {
                return (int) ($row->credits ?? 0) > 0;
            }));
        } elseif ($filterCredits === 'none') {
            $rows = array_values(array_filter($rows, function ($row) {
                return (int) ($row->credits ?? 0) === 0;
            }));
        }

        usort($rows, function ($left, $right) {
            return strcasecmp($left->display_name ?? '', $right->display_name ?? '');
        });

        return $rows;
    }
}