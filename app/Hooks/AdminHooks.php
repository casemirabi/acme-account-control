<?php

declare(strict_types=1);

namespace Acme\AccountControl\Hooks;

/**
 * Centraliza hooks administrativos do plugin.
 *
 * Responsabilidade:
 * Agrupar menus do painel, admin_post, telas administrativas e rotinas de
 * gerenciamento visíveis apenas no wp-admin.
 *
 * Cuidado de compatibilidade:
 * Slugs de menu, nomes de actions e callbacks públicos não devem ser alterados,
 * pois podem estar salvos em bookmarks, permissões, formulários e integrações.
 */
final class AdminHooks
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
     * Registra/carrega hooks administrativos.
     */
    public function register(): void
    {
        $this->legacyHookRegistry->register();
    }
}
