<?php

declare(strict_types=1);

namespace Acme\AccountControl;

use Acme\AccountControl\Hooks\ActivationHooks;
use Acme\AccountControl\Hooks\LegacyHookRegistry;
use Acme\AccountControl\Services\VendorLoader;

/**
 * Bootstrap principal do ACME Account Control.
 *
 * Responsabilidade:
 * Coordenar a inicialização do plugin sem concentrar regra de negócio. Este é o
 * ponto de entrada da arquitetura MVC simplificada.
 *
 * O que este arquivo NÃO deve fazer:
 * - Registrar hooks específicos de tela, AJAX ou REST diretamente.
 * - Executar queries.
 * - Processar regra de negócio.
 * - Renderizar HTML.
 *
 * Essas responsabilidades devem ficar em Controllers, Services, Models, Hooks e
 * Views conforme a migração evoluir.
 */
final class Bootstrap
{
    /** @var string */
    private $pluginFile;

    /** @var string */
    private $pluginPath;

    public function __construct(string $pluginFile)
    {
        $this->pluginFile = $pluginFile;
        $this->pluginPath = plugin_dir_path($pluginFile);
    }

    /**
     * Inicializa o plugin preservando compatibilidade com o código legado.
     */
    public function run(): void
    {
        $this->loadConfiguration();
        $this->loadVendors();
        $this->registerLegacyHooks();
        $this->registerActivationHooks();
    }

    /**
     * Carrega constantes e configurações centrais.
     */
    private function loadConfiguration(): void
    {
        require_once $this->pluginPath . 'config/constants.php';
    }

    /**
     * Carrega bibliotecas externas usadas por relatórios e PDFs.
     */
    private function loadVendors(): void
    {
        (new VendorLoader($this->pluginPath))->load();
    }

    /**
     * Carrega módulos legados que ainda registram hooks diretamente.
     */
    private function registerLegacyHooks(): void
    {
        $legacyFiles = require $this->pluginPath . 'config/legacy-files.php';
        (new LegacyHookRegistry($this->pluginPath, $legacyFiles))->register();
    }

    /**
     * Registra rotinas de ativação do WordPress em uma classe dedicada.
     */
    private function registerActivationHooks(): void
    {
        (new ActivationHooks())->register($this->pluginFile);
    }
}
