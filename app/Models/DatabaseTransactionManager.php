<?php

declare(strict_types=1);

namespace Acme\AccountControl\Models;

/**
 * Gerenciador simples de transações SQL.
 *
 * Objetivo:
 * Remover comandos `START TRANSACTION`, `COMMIT` e `ROLLBACK` dos Services sem
 * introduzir uma abstração complexa. O WordPress não oferece uma camada nativa
 * completa para transações, então mantemos comandos SQL explícitos e pequenos.
 *
 * Risco:
 * Só deve ser usado em fluxos críticos que realmente precisam de atomicidade,
 * como concessão de créditos. Uso indiscriminado pode aumentar locks no banco.
 */
final class DatabaseTransactionManager
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
     * Inicia uma transação SQL manual.
     */
    public function begin(): void
    {
        $this->wpdb->query('START TRANSACTION');
    }

    /**
     * Confirma a transação atual.
     */
    public function commit(): void
    {
        $this->wpdb->query('COMMIT');
    }

    /**
     * Desfaz a transação atual para preservar consistência dos créditos.
     */
    public function rollback(): void
    {
        $this->wpdb->query('ROLLBACK');
    }
}
