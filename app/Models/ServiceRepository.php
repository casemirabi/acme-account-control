<?php

declare(strict_types=1);

namespace Acme\AccountControl\Models;

/**
 * Repositório de serviços cadastrados no ACME.
 *
 * Objetivo:
 * Centralizar queries da tabela de serviços para impedir que `SELECT` por slug,
 * ID ou listagem administrativa fiquem espalhados por controllers, shortcodes e
 * funções globais legadas.
 *
 * Compatibilidade:
 * Usa `acme_table_services()` quando disponível, preservando o nome físico da
 * tabela definido pelo plugin legado. Isso evita quebra em instalações que já
 * possuem dados em produção.
 *
 * Risco:
 * Esta classe depende do `$wpdb` global e das funções de tabela do ACME. Por
 * isso, deve ser instanciada apenas depois do carregamento do WordPress e dos
 * helpers legados.
 */
final class ServiceRepository
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
     * Retorna o nome da tabela de serviços respeitando a função legada.
     */
    private function tableName(): string
    {
        return function_exists('acme_table_services')
            ? acme_table_services()
            : $this->wpdb->prefix . 'services';
    }

    /**
     * Busca um serviço pelo slug público.
     *
     * IMPORTANTE:
     * O slug é utilizado por shortcodes, URLs internas, regras de crédito e
     * integrações externas. A query foi preservada para manter compatibilidade.
     *
     * @return object|null
     */
    public function findBySlug(string $slug)
    {
        $servicesTable = $this->tableName();

        return $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT id, slug, name, credits_cost FROM {$servicesTable} WHERE slug=%s LIMIT 1",
                $slug
            )
        );
    }

    /**
     * Busca os campos mínimos de identidade de um serviço.
     *
     * Usado por logs e relatórios para preencher `service_slug` e `service_name`
     * sem duplicar SQL fora da camada de Model.
     *
     * @return object|null
     */
    public function findIdentityById(int $serviceId)
    {
        $servicesTable = $this->tableName();

        return $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT slug, name FROM {$servicesTable} WHERE id = %d LIMIT 1",
                $serviceId
            )
        );
    }

    /**
     * Lista serviços para telas administrativas e filtros.
     *
     * Mantemos a ordenação por nome porque esse comportamento já era usado nas
     * telas legadas e pode afetar a experiência do usuário no painel.
     *
     * @return array<int, object>
     */
    public function listForSelection(): array
    {
        $servicesTable = $this->tableName();

        return (array) $this->wpdb->get_results(
            "SELECT slug, name, credits_cost FROM {$servicesTable} ORDER BY name ASC"
        );
    }
}
