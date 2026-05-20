<?php

declare(strict_types=1);

namespace Acme\AccountControl\Hooks;

/**
 * Centraliza hooks e rotas REST API.
 *
 * Responsabilidade:
 * Agrupar chamadas de `rest_api_init` e `register_rest_route` relacionadas aos
 * endpoints públicos/privados do plugin.
 *
 * Compatibilidade:
 * Namespace, paths e permissões das rotas precisam permanecer estáveis para não
 * quebrar consumidores externos ou integrações assíncronas.
 */
final class RestApiHooks
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
     * Registra/carrega rotas REST API.
     */
    public function register(): void
    {
        $this->legacyHookRegistry->register();
    }
}
