<?php

declare(strict_types=1);

namespace Acme\AccountControl\Hooks;

/**
 * Centraliza hooks de relatórios e exportações.
 *
 * Responsabilidade:
 * Agrupar shortcodes, filtros de registry e actions admin_post envolvidos na
 * exportação de dados.
 *
 * Cuidado:
 * Exportações costumam depender de permissões, nonce e formato de saída. A
 * migração posterior deve preservar nomes de parâmetros e comportamento atual.
 */
final class ReportHooks
{
    /** @var LegacyHookRegistry */
    private $legacyHookRegistry;

    /**
     * @param array<int, array{path:string, required:bool}> $legacyFiles
     */
    public function __construct(string $pluginPath, array $legacyFiles)
    {
        $this->legacyHookRegistry = new LegacyHookRegistry($pluginPath, $legacyFiles);
    }

    /**
     * Registra/carrega relatórios e exportações.
     */
    public function register(): void
    {
        $this->legacyHookRegistry->register();
    }
}
