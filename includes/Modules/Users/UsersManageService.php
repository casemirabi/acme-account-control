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
        return acme_users_repo_get_actor_credit_summary($userId);
    }
}

#===================================
if (!function_exists('acme_users_manage_get_base_rows')) {
    function acme_users_manage_get_base_rows(int $actorUserId, bool $isAdmin): array
    {
        return acme_users_repo_get_manage_base_rows($actorUserId, $isAdmin);
    }
}

#===================================
if (!function_exists('acme_users_manage_get_credits_map')) {
    function acme_users_manage_get_credits_map(array $rows): array
    {
        return acme_users_repo_get_credits_map($rows);
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

if (!function_exists('acme_users_manage_get_master_filter_users')) {
    function acme_users_manage_get_master_filter_users(): array
    {
        return acme_users_repo_get_children_for_filter();
    }
}

if (!function_exists('acme_users_manage_get_messages')) {
    function acme_users_manage_get_messages(): array
    {
        $messages = [];
        $messageCode = isset($_GET['acme_msg']) ? sanitize_text_field(wp_unslash((string) $_GET['acme_msg'])) : '';
        $errorMessage = isset($_GET['acme_err']) ? sanitize_text_field(wp_unslash((string) $_GET['acme_err'])) : '';

        $styles = [
            'success' => 'background:#ecfdf5;border:1px solid #a7f3d0;color:#065f46;font-weight:900;',
            'danger'  => 'background:#fef2f2;border:1px solid #fecaca;color:#991b1b;font-weight:900;',
            'warn'    => 'background:#fff7ed;border:1px solid #fdba74;color:#9a3412;font-weight:900;',
            'soft'    => 'background:#fff1f2;border:1px solid #fecaca;color:#991b1b;font-weight:900;',
        ];

        if ($messageCode === 'ok') {
            $messages[] = ['style' => $styles['success'], 'text' => 'Status atualizado.'];
        } elseif ($messageCode === 'pass') {
            $messages[] = ['style' => $styles['success'], 'text' => 'Senha alterada e sessão do usuário encerrada.'];
        } elseif ($messageCode === 'phone') {
            $messages[] = ['style' => $styles['success'], 'text' => 'Telefone atualizado.'];
        } elseif ($messageCode === 'created') {
            $messages[] = ['style' => $styles['success'], 'text' => 'Usuário criado com sucesso.'];
        } elseif ($messageCode === 'error') {
            $messages[] = ['style' => $styles['danger'], 'text' => $errorMessage ?: 'Erro ao criar usuário. Verifique os dados informados.'];
        } elseif ($messageCode === 'missing_parent') {
            $messages[] = ['style' => $styles['warn'], 'text' => 'Selecione um Master responsável ativo para criar o Sub-Login.'];
        } elseif ($messageCode === 'parent_inactive') {
            $messages[] = ['style' => $styles['warn'], 'text' => 'Não é permitido criar Sub-Login para Master inativo.'];
        } elseif ($messageCode === 'bulk_ok') {
            $count = isset($_GET['bulk_count']) ? (int) $_GET['bulk_count'] : 0;
            $messages[] = ['style' => $styles['success'], 'text' => 'Ativação em massa concluída. Usuários ativados: ' . $count . '.'];

            $skippedCount = isset($_GET['bulk_skipped']) ? (int) $_GET['bulk_skipped'] : 0;
            if ($skippedCount > 0) {
                $messages[] = ['style' => $styles['warn'], 'text' => 'Atenção: ' . $skippedCount . ' Sub-Login(s) não foram ativados porque o Master está inativo (ou vínculo ausente).'];
            }
        } elseif ($messageCode === 'bulk_none') {
            $messages[] = ['style' => $styles['danger'], 'text' => 'Nenhum usuário selecionado (ou você não tem permissão).'];
        } elseif ($messageCode === 'bulk_deact_ok') {
            $count = isset($_GET['bulk_count']) ? (int) $_GET['bulk_count'] : 0;
            $messages[] = ['style' => $styles['soft'], 'text' => 'Inativação em massa concluída. Usuários inativados: ' . $count . '.'];
        } elseif ($messageCode === 'err_master') {
            $messages[] = ['style' => $styles['warn'], 'text' => $errorMessage ?: 'Não foi possível ativar.'];
        } elseif ($messageCode === 'bulk_master_block') {
            $skippedCount = isset($_GET['bulk_skipped']) ? (int) $_GET['bulk_skipped'] : 0;
            $messages[] = ['style' => $styles['warn'], 'text' => 'Nenhum usuário foi ativado: existem Sub-Logins cujo Master está inativo (ou sem vínculo). Bloqueados: ' . $skippedCount . '.'];
        }

        return $messages;
    }
}

if (!function_exists('acme_users_manage_build_screen_data')) {
    function acme_users_manage_build_screen_data(int $currentUserId): array
    {
        $currentUser = wp_get_current_user();
        $isAdmin = user_can($currentUserId, 'administrator');
        $isChild = in_array('child', (array) $currentUser->roles, true);

        $filters = acme_users_manage_get_filters($isAdmin);

        $creditSummary = acme_users_manage_get_actor_credit_summary($currentUserId);
        $rows = acme_users_manage_get_base_rows($currentUserId, $isAdmin);

        $creditsMap = acme_users_manage_get_credits_map($rows);
        foreach ($rows as $row) {
            $row->credits = $creditsMap[(int) $row->ID] ?? 0;
        }

        $rows = acme_users_manage_filter_rows($rows, $filters, $isAdmin);

        $scopeIds = [];
        foreach ($rows as $row) {
            if (($row->acme_type ?? '') === acme_role_label('grandchild')) {
                $scopeIds[] = (int) $row->ID;
            }
        }
        $scopeIds = array_values(array_unique($scopeIds));

        $baseUrl = remove_query_arg(['acme_msg', 'acme_err']);
        $hasAnyFilter = ($filters['q'] !== '')
            || ($isAdmin && (int) $filters['filter_master'] > 0)
            || ($filters['filter_status'] !== 'all')
            || ($filters['filter_credits'] !== 'all');

        return [
            'isAdmin' => $isAdmin,
            'isChild' => $isChild,
            'filters' => $filters,
            'rows' => $rows,
            'baseUrl' => $baseUrl,
            'clearFiltersUrl' => remove_query_arg(['q', 'master', 'status', 'credits', 'acme_msg']),
            'hasAnyFilter' => $hasAnyFilter,
            'messages' => acme_users_manage_get_messages(),
            'childrenForFilter' => $isAdmin ? acme_users_manage_get_master_filter_users() : [],
            'scopeIds' => $scopeIds,
            'creditTotalAvailable' => $creditSummary['credit_total_available'],
            'creditBreakdown' => $creditSummary['credit_breakdown'],
        ];
    }
}
