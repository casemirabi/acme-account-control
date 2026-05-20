<?php

declare(strict_types=1);

namespace Acme\AccountControl\Controllers\Admin;

use Acme\AccountControl\Security\RequestGuard;

/**
 * Controller base para ações administrativas simples.
 *
 * Objetivo:
 * Concentrar validações comuns de wp-admin sem criar uma hierarquia complexa.
 * Esta classe é pequena de propósito: ela existe apenas para reduzir repetição
 * ao migrar callbacks `admin_post` e páginas administrativas gradualmente.
 *
 * Uso esperado:
 * Controllers específicos podem chamar `assertManageOptions()` e
 * `redirectToAdminPage()` para manter validações consistentes com WordPress.
 */
class AdminPageController
{
    /** @var RequestGuard */
    protected $requestGuard;

    public function __construct(?RequestGuard $requestGuard = null)
    {
        // Permitimos injeção opcional para testes futuros, mas criamos uma
        // instância padrão para manter uso simples nos Controllers atuais.
        $this->requestGuard = $requestGuard ?: new RequestGuard();
    }
    /**
     * Garante que o usuário atual tenha permissão administrativa.
     *
     * Risco mitigado:
     * Evita que callbacks administrativos sejam executados por usuários sem
     * capability adequada. Mantemos `manage_options` porque já era a regra usada
     * pelos fluxos legados do plugin.
     */
    protected function assertManageOptions(): void
    {
        $this->requestGuard->requireCapability('manage_options');
    }

    /**
     * Redireciona para uma página administrativa preservando o slug público.
     */
    protected function redirectToAdminPage(string $pageSlug): void
    {
        $this->requestGuard->redirectToAdminPage($pageSlug);
    }
}
