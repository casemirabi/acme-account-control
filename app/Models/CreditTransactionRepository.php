<?php

declare(strict_types=1);

namespace Acme\AccountControl\Models;

/**
 * Repositório de transações de crédito.
 *
 * Objetivo:
 * Isolar persistência de auditoria financeira. Transações de crédito são dados
 * sensíveis para relatórios e rastreabilidade, então qualquer alteração nesta
 * camada deve ser feita com testes de regressão.
 *
 * Compatibilidade:
 * A tabela continua sendo resolvida por `acme_table_credit_transactions()`.
 */
final class CreditTransactionRepository
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
     * Retorna a tabela de transações com fallback para testes isolados.
     */
    private function tableName(): string
    {
        return function_exists('acme_table_credit_transactions')
            ? acme_table_credit_transactions()
            : $this->wpdb->prefix . 'credit_transactions';
    }

    /**
     * Insere uma transação de crédito.
     *
     * O array deve seguir o schema legado porque relatórios, filtros e auditorias
     * dependem desses nomes de coluna.
     *
     * @return int|false Mesmo retorno do `$wpdb->insert()`.
     */
    public function insert(array $transactionData)
    {
        return $this->wpdb->insert($this->tableName(), $transactionData);
    }
}
