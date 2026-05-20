<?php

declare(strict_types=1);

namespace Acme\AccountControl\Hooks;

/**
 * Registrador central de hooks do ACME.
 *
 * Objetivo:
 * Ser o ponto único de entrada para todos os hooks carregados pelo plugin,
 * evitando que o bootstrap principal precise conhecer detalhes de admin,
 * frontend, REST API, AJAX, cron ou compatibilidade legada.
 *
 * Motivo da implementação:
 * O plugin ainda possui arquivos de hooks que registram hooks no momento do
 * `require_once`. Em vez de mover toda regra de uma vez, esta classe organiza o
 * carregamento por grupos funcionais. Isso melhora localização e manutenção sem
 * alterar nomes públicos, slugs, endpoints, actions ou shortcodes existentes.
 *
 * Risco controlado:
 * A ordem dos grupos deve permanecer compatível com a ordem anterior de
 * `config/hook-files.php`, pois alguns arquivos dependem de funções globais
 * definidas por módulos carregados antes deles.
 */
final class HookRegistrar
{
    /** @var string */
    private $pluginPath;

    /** @var array<string, array<int, array{path:string, required:bool}>> */
    private $hookGroups;

    /**
     * @param string $pluginPath Diretório absoluto do plugin.
     * @param array<string, array<int, array{path:string, required:bool}>> $hookGroups Manifesto agrupado de hooks.
     */
    public function __construct(string $pluginPath, array $hookGroups)
    {
        $this->pluginPath = rtrim($pluginPath, '/\\') . DIRECTORY_SEPARATOR;
        $this->hookGroups = $hookGroups;
    }

    /**
     * Registra todos os grupos de hooks do plugin.
     *
     * A sequência abaixo é propositalmente explícita para facilitar manutenção:
     * primeiro carregamos compatibilidade e usuários, depois admin/frontend,
     * rotas REST, jobs assíncronos e relatórios.
     */
    public function register(): void
    {
        (new CompatibilityHooks($this->pluginPath, $this->group('compatibility_base')))->register();
        (new UserHooks($this->pluginPath, $this->group('users')))->register();
        (new CompatibilityHooks($this->pluginPath, $this->group('business_core')))->register();
        (new FrontendHooks($this->pluginPath, $this->group('frontend')))->register();
        (new AdminHooks($this->pluginPath, $this->group('admin')))->register();
        (new AdminHooks($this->pluginPath, $this->group('credit_admin')))->register();
        (new RestApiHooks($this->pluginPath, $this->group('external_api')))->register();
        (new RestApiHooks($this->pluginPath, $this->group('rest_api')))->register();
        (new AjaxHooks($this->pluginPath, $this->group('ajax')))->register();
        (new CronHooks($this->pluginPath, $this->group('cron')))->register();
        (new ReportHooks($this->pluginPath, $this->group('reports')))->register();
    }

    /**
     * Retorna um grupo de arquivos do manifesto.
     *
     * Mantemos fallback para array vazio porque alguns ambientes podem remover
     * módulos opcionais. A obrigatoriedade real de cada arquivo continua sendo
     * respeitada dentro de HookFileLoader.
     *
     * @return array<int, array{path:string, required:bool}>
     */
    private function group(string $name): array
    {
        return isset($this->hookGroups[$name]) && is_array($this->hookGroups[$name])
            ? $this->hookGroups[$name]
            : [];
    }
}
