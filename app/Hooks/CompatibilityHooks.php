<?php

declare(strict_types=1);

namespace Acme\AccountControl\Hooks;

/**
 * Carrega hooks e helpers de compatibilidade legada.
 *
 * Objetivo:
 * Garantir que funções globais, filtros de segurança REST, suporte a APIs e
 * integrações antigas continuem disponíveis antes dos módulos principais.
 *
 * Impacto no WordPress:
 * Esses arquivos podem registrar filtros globais e ações usados por admin,
 * frontend e endpoints. Por isso eles são carregados no início da sequência.
 */
final class CompatibilityHooks
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
     * Registra/carrega compatibilidade legada.
     */
    public function register(): void
    {
        $this->legacyHookRegistry->register();
    }
}
