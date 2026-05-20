<?php

declare(strict_types=1);

namespace Acme\AccountControl\Hooks;

/**
 * Centraliza o carregamento dos hooks relacionados a usuários.
 *
 * Responsabilidade:
 * Agrupar cadastro, login guard, campos administrativos, ações admin_post,
 * shortcodes de usuário e persistência relacionada à hierarquia de contas.
 *
 * Compatibilidade:
 * Os nomes de callbacks globais antigos permanecem intactos, pois esta etapa
 * apenas organiza o carregamento. A migração fina para Controllers/Services pode
 * acontecer depois, módulo por módulo.
 */
final class UserHooks
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
     * Carrega hooks de usuários e hierarquia de contas.
     */
    public function register(): void
    {
        $this->hookFileLoader->register();
    }
}
