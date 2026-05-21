<?php

declare(strict_types=1);

namespace Acme\AccountControl\Services;

/**
 * Service responsável por regras de escopo hierárquico do Login Master.
 *
 * A tabela account_links já trabalha como closure table: cada relação direta ou
 * indireta pode ser consultada por parent_user_id -> child_user_id. Por isso,
 * o master enxerga filhos, netos e níveis futuros sem mudar o controller.
 */
final class MasterAccessService
{
    /**
     * Retorna todos os usuários acessíveis pelo ator, incluindo o próprio ator.
     */
    public function getVisibleUserIds(int $actorUserId): array
    {
        if ($actorUserId <= 0) {
            return [];
        }

        if (user_can($actorUserId, 'manage_options')) {
            return $this->getAllManagedUserIds($actorUserId);
        }

        global $wpdb;
        $linksTable = function_exists('acme_table_links') ? acme_table_links() : $wpdb->prefix . 'account_links';

        $descendantIds = $wpdb->get_col($wpdb->prepare(
            "SELECT child_user_id
             FROM {$linksTable}
             WHERE parent_user_id = %d",
            $actorUserId
        ));

        $visibleUserIds = array_merge([$actorUserId], array_map('intval', (array) $descendantIds));

        return array_values(array_unique(array_filter($visibleUserIds)));
    }

    /**
     * Verifica se o ator pode consultar recursos do usuário alvo.
     */
    public function canAccessUser(int $actorUserId, int $targetUserId): bool
    {
        if ($actorUserId <= 0 || $targetUserId <= 0) {
            return false;
        }

        if ($actorUserId === $targetUserId) {
            return true;
        }

        if (user_can($actorUserId, 'manage_options')) {
            return true;
        }

        global $wpdb;
        $linksTable = function_exists('acme_table_links') ? acme_table_links() : $wpdb->prefix . 'account_links';

        $linkId = $wpdb->get_var($wpdb->prepare(
            "SELECT id
             FROM {$linksTable}
             WHERE parent_user_id = %d
               AND child_user_id = %d
             LIMIT 1",
            $actorUserId,
            $targetUserId
        ));

        return !empty($linkId);
    }

    /**
     * Para administradores, mantém acesso amplo, mas ainda retorna IDs explícitos.
     */
    private function getAllManagedUserIds(int $actorUserId): array
    {
        $userIds = get_users([
            'fields' => 'ID',
            'number' => 5000,
        ]);

        $userIds = array_map('intval', (array) $userIds);
        $userIds[] = $actorUserId;

        return array_values(array_unique(array_filter($userIds)));
    }
}
