<?php

declare(strict_types=1);

namespace Acme\AccountControl\Hooks;

/**
 * Centraliza hooks de frontend e shortcodes.
 *
 * Responsabilidade:
 * Agrupar shortcodes, carregamento de assets públicos e fluxos renderizados fora
 * do painel administrativo.
 *
 * Risco controlado:
 * Shortcodes são contratos públicos. Por isso, suas tags antigas devem continuar
 * exatamente iguais durante toda a refatoração.
 */
final class FrontendHooks
{
    /** @var HookFileLoader */
    private $hookFileLoader;

    /**
     * @param array<int, array{path:string, required:bool}> $hookFiles
     */
    public function __construct(string $pluginPath, array $hookFiles)
    {
        $this->hookFileLoader = new HookFileLoader($pluginPath, $hookFiles);
    }

    /**
     * Registra/carrega shortcodes e assets públicos.
     */
    public function register(): void
    {
        $this->hookFileLoader->register();
    }
}
