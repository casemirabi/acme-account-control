<?php

declare(strict_types=1);

namespace Acme\AccountControl\Models;

/**
 * Repositório de créditos do ACME.
 *
 * Objetivo:
 * Concentrar queries relacionadas a serviços, carteiras e transações de crédito.
 *
 * Motivo da implementação:
 * O plugin legado executava SQL diretamente dentro de funções globais de serviço.
 * Ao isolar a persistência neste Model, reduzimos acoplamento e deixamos as
 * regras de negócio mais fáceis de testar e manter.
 *
 * Compatibilidade:
 * Esta classe usa as mesmas tabelas resolvidas pelas funções legadas
 * `acme_table_services()`, `acme_table_wallet()` e
 * `acme_table_credit_transactions()`. Isso preserva nomes de tabelas, schema,
 * queries e comportamento atual.
 *
 * Risco:
 * As queries ainda dependem do objeto global `$wpdb` e das funções de tabela do
 * WordPress/ACME. Por isso, este Model deve ser carregado somente após o núcleo
 * do WordPress estar disponível e após `includes/support/helpers.php`.
 */
final class CreditRepository
{
    /** @var \wpdb */
    private $wpdb;

    /**
     * @param \wpdb $wpdb Instância global do banco de dados do WordPress.
     */
    public function __construct($wpdb)
    {
        $this->wpdb = $wpdb;
    }

    /**
     * Retorna detalhes técnicos do último erro SQL.
     *
     * Mantemos este método para preservar a mensagem diagnóstica usada pelo
     * legado em falhas de transação, sem espalhar acesso ao `$wpdb` nos Services.
     */
    public function describeLastDatabaseError(string $context): string
    {
        $lastError = $this->wpdb->last_error ?: 'sem last_error';
        $lastQuery = $this->wpdb->last_query ?: 'sem last_query';

        return $context . " | DB_ERROR: {$lastError} | LAST_QUERY: {$lastQuery}";
    }

    /**
     * Busca um serviço pelo slug público.
     *
     * IMPORTANTE:
     * O slug é contrato público usado por shortcodes, telas e integrações. A
     * query foi preservada para evitar qualquer mudança de comportamento.
     *
     * @return object|null
     */
    public function findServiceBySlug(string $slug)
    {
        $servicesTable = acme_table_services();

        return $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT id, slug, name, credits_cost FROM {$servicesTable} WHERE slug=%s LIMIT 1",
                $slug
            )
        );
    }

    /**
     * Busca dados mínimos de serviço pelo ID.
     *
     * Usado para preencher `service_slug` e `service_name` automaticamente ao
     * registrar transações, mantendo compatibilidade com relatórios existentes.
     *
     * @return object|null
     */
    public function findServiceIdentityById(int $serviceId)
    {
        $servicesTable = acme_table_services();

        return $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT slug, name FROM {$servicesTable} WHERE id = %d LIMIT 1",
                $serviceId
            )
        );
    }

    /**
     * Lê a carteira de créditos de um usuário para um serviço.
     *
     * A relação `master_user_id + service_id` foi mantida exatamente como no
     * legado para não alterar regras de saldo ou relatórios existentes.
     *
     * @return object|null
     */
    public function findWallet(int $userId, int $serviceId)
    {
        $walletTable = acme_table_wallet();

        return $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT * FROM {$walletTable} WHERE master_user_id=%d AND service_id=%d LIMIT 1",
                $userId,
                $serviceId
            )
        );
    }

    /**
     * Insere uma nova carteira de créditos.
     *
     * Retorna `false` em falha para preservar a semântica do `$wpdb->insert()` e
     * permitir rollback na camada de Service.
     *
     * @return int|false
     */
    public function insertWallet(array $walletData)
    {
        return $this->wpdb->insert(acme_table_wallet(), $walletData);
    }

    /**
     * Atualiza uma carteira existente.
     *
     * @return int|false
     */
    public function updateWallet(int $walletId, array $walletData)
    {
        return $this->wpdb->update(acme_table_wallet(), $walletData, ['id' => $walletId]);
    }

    /**
     * Insere uma transação de crédito.
     *
     * A estrutura do array recebido segue o schema legado da tabela de
     * transações, incluindo campos usados por relatórios e auditoria.
     *
     * @return int|false
     */
    public function insertCreditTransaction(array $transactionData)
    {
        return $this->wpdb->insert(acme_table_credit_transactions(), $transactionData);
    }

    /**
     * Retorna o ID do último registro inserido pelo WordPress.
     */
    public function getLastInsertId(): int
    {
        return (int) $this->wpdb->insert_id;
    }

    /**
     * Inicia transação SQL manual.
     *
     * IMPORTANTE:
     * O WordPress não possui abstração nativa completa para transações. Como o
     * legado já usava SQL direto, mantemos o mesmo comportamento para evitar
     * mudança de atomicidade durante concessão de créditos.
     */
    public function startTransaction(): void
    {
        $this->wpdb->query('START TRANSACTION');
    }

    /**
     * Confirma transação SQL manual.
     */
    public function commit(): void
    {
        $this->wpdb->query('COMMIT');
    }

    /**
     * Desfaz transação SQL manual.
     */
    public function rollback(): void
    {
        $this->wpdb->query('ROLLBACK');
    }

    /**
     * Indica se a última operação SQL registrou erro.
     */
    public function hasLastError(): bool
    {
        return (bool) $this->wpdb->last_error;
    }
}
