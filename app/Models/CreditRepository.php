<?php

declare(strict_types=1);

namespace Acme\AccountControl\Models;

/**
 * Fachada de persistência do módulo de créditos.
 *
 * Objetivo:
 * Preservar a API interna criada na etapa anterior (`CreditRepository`) enquanto
 * separamos as responsabilidades reais em repositórios menores:
 * - ServiceRepository
 * - WalletRepository
 * - CreditTransactionRepository
 * - DatabaseTransactionManager
 *
 * Motivo da implementação:
 * Esta abordagem reduz risco na refatoração. Os Services já dependiam de
 * `CreditRepository`; trocar tudo de uma vez aumentaria chance de regressão. A
 * fachada mantém compatibilidade e delega o trabalho para Models específicos.
 *
 * Compatibilidade:
 * Métodos públicos existentes foram mantidos para não quebrar wrappers legados
 * como `acme_credits_grant()`, `acme_wallet_get()` e `acme_service_get_by_slug()`.
 *
 * Próximo passo:
 * Quando o plugin estiver totalmente coberto por testes, os Services podem
 * passar a depender diretamente dos repositórios específicos, removendo esta
 * fachada gradualmente.
 */
final class CreditRepository
{
    /** @var \wpdb */
    private $wpdb;

    /** @var ServiceRepository */
    private $serviceRepository;

    /** @var WalletRepository */
    private $walletRepository;

    /** @var CreditTransactionRepository */
    private $transactionRepository;

    /** @var DatabaseTransactionManager */
    private $transactionManager;

    /**
     * @param \wpdb $wpdb Instância global do banco de dados do WordPress.
     */
    public function __construct($wpdb)
    {
        $this->wpdb = $wpdb;
        $this->serviceRepository = new ServiceRepository($wpdb);
        $this->walletRepository = new WalletRepository($wpdb);
        $this->transactionRepository = new CreditTransactionRepository($wpdb);
        $this->transactionManager = new DatabaseTransactionManager($wpdb);
    }

    /**
     * Retorna detalhes técnicos do último erro SQL.
     *
     * Mantemos este método centralizado para preservar diagnóstico sem espalhar
     * acesso direto ao `$wpdb` pelos Services.
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
     * @return object|null
     */
    public function findServiceBySlug(string $slug)
    {
        return $this->serviceRepository->findBySlug($slug);
    }

    /**
     * Busca dados mínimos de serviço pelo ID.
     *
     * @return object|null
     */
    public function findServiceIdentityById(int $serviceId)
    {
        return $this->serviceRepository->findIdentityById($serviceId);
    }

    /**
     * Lista serviços para selects administrativos.
     *
     * @return array<int, object>
     */
    public function listServicesForSelection(): array
    {
        return $this->serviceRepository->listForSelection();
    }

    /**
     * Lê a carteira de créditos de um usuário para um serviço.
     *
     * @return object|null
     */
    public function findWallet(int $userId, int $serviceId)
    {
        return $this->walletRepository->findByUserAndService($userId, $serviceId);
    }

    /**
     * Insere uma nova carteira de créditos.
     *
     * @return int|false
     */
    public function insertWallet(array $walletData)
    {
        return $this->walletRepository->insert($walletData);
    }

    /**
     * Atualiza uma carteira existente.
     *
     * @return int|false
     */
    public function updateWallet(int $walletId, array $walletData)
    {
        return $this->walletRepository->update($walletId, $walletData);
    }

    /**
     * Insere uma transação de crédito.
     *
     * @return int|false
     */
    public function insertCreditTransaction(array $transactionData)
    {
        return $this->transactionRepository->insert($transactionData);
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
     */
    public function startTransaction(): void
    {
        $this->transactionManager->begin();
    }

    /**
     * Confirma transação SQL manual.
     */
    public function commit(): void
    {
        $this->transactionManager->commit();
    }

    /**
     * Desfaz transação SQL manual.
     */
    public function rollback(): void
    {
        $this->transactionManager->rollback();
    }

    /**
     * Indica se a última operação SQL registrou erro.
     */
    public function hasLastError(): bool
    {
        return (bool) $this->wpdb->last_error;
    }
}
