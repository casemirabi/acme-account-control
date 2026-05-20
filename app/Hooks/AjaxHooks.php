<?php

declare(strict_types=1);

namespace Acme\AccountControl\Hooks;

/**
 * Centraliza hooks AJAX do WordPress.
 *
 * Responsabilidade:
 * Agrupar callbacks `wp_ajax_*` usados por geração de PDF, solicitações
 * assíncronas e formulários que dependem de admin-ajax.php.
 *
 * Observação:
 * Mesmo quando o arquivo legado contém helpers além de AJAX, ele fica neste grupo
 * se o principal contrato público dele for uma action AJAX.
 */
final class AjaxHooks
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
     * Registra/carrega callbacks AJAX.
     */
    public function register(): void
    {
        $this->hookFileLoader->register();
    }
}
