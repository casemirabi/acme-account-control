<?php

declare(strict_types=1);

namespace Acme\AccountControl\Models;

/**
 * Repositório da carteira de créditos.
 *
 * Objetivo:
 * Concentrar leitura e escrita de saldo em uma classe pequena. Isso reduz o
 * risco de regras diferentes manipularem a mesma tabela com SQL divergente.
 *
 * Compatibilidade:
 * A tabela continua sendo resolvida por `acme_table_wallet()`, preservando o
 * schema e os dados existentes.
 */
final class WalletRepository
{
    /** @var \wpdb */
    private $wpdb;

    /**
     * @param \wpdb $wpdb Instância do banco de dados do WordPress.
     */
    public function __construct($wpdb)
    {
        $this->wpdb = $wpdb;
    }

    /**
     * Retorna a tabela de wallet mantendo fallback seguro para ambientes de teste.
     */
    private function tableName(): string
    {
        return function_exists('acme_table_wallet')
            ? acme_table_wallet()
            : $this->wpdb->prefix . 'credit_wallet';
    }

    /**
     * Lê a carteira de um usuário para um serviço.
     *
     * A chave `master_user_id + service_id` foi preservada porque faz parte do
     * modelo de permissões e saldo do plugin legado.
     *
     * @return object|null
     */
    public function findByUserAndService(int $userId, int $serviceId)
    {
        $walletTable = $this->tableName();

        return $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT * FROM {$walletTable} WHERE master_user_id=%d AND service_id=%d LIMIT 1",
                $userId,
                $serviceId
            )
        );
    }

    /**
     * Cria uma carteira de créditos.
     *
     * @return int|false Mesmo retorno do `$wpdb->insert()` para compatibilidade.
     */
    public function insert(array $walletData)
    {
        return $this->wpdb->insert($this->tableName(), $walletData);
    }

    /**
     * Atualiza uma carteira existente.
     *
     * @return int|false Mesmo retorno do `$wpdb->update()` para compatibilidade.
     */
    public function update(int $walletId, array $walletData)
    {
        return $this->wpdb->update($this->tableName(), $walletData, ['id' => $walletId]);
    }
}
