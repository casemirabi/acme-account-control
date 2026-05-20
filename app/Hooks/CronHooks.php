<?php

declare(strict_types=1);

namespace Acme\AccountControl\Hooks;

/**
 * Centraliza hooks de cron e jobs assíncronos.
 *
 * Responsabilidade:
 * Agrupar actions executadas fora do ciclo normal de request, como finalização
 * de consultas, débito assíncrono de créditos e integrações dependentes de fila.
 *
 * Risco:
 * Alterações nesses hooks podem duplicar cobranças, impedir baixa de créditos ou
 * deixar requests pendentes. Por isso, esta etapa apenas centraliza carregamento.
 */
final class CronHooks
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
     * Registra/carrega jobs assíncronos e hooks de cron.
     */
    public function register(): void
    {
        $this->hookFileLoader->register();
    }
}
