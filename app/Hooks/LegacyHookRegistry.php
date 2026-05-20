<?php

declare(strict_types=1);

namespace Acme\AccountControl\Hooks;

use Acme\AccountControl\Helpers\SafeRequire;

/**
 * Carrega os módulos legados que ainda registram hooks diretamente.
 *
 * Objetivo:
 * Concentrar, em um único ponto, todos os arquivos que chamam `add_action`,
 * `add_filter`, `add_shortcode`, `register_rest_route`, AJAX ou admin_post.
 *
 * Motivo da implementação:
 * O plugin original registra hooks no topo de vários arquivos. Mover tudo de uma
 * vez para classes poderia quebrar comportamento público. Esta classe cria uma
 * etapa segura: todos os carregamentos ficam centralizados e documentados, mas as
 * funções antigas continuam disponíveis.
 *
 * Próxima etapa recomendada:
 * Migrar módulo por módulo para classes em `app/Controllers`, `app/Services`,
 * `app/Models` e `app/Hooks`, mantendo wrappers legados quando nomes públicos
 * precisarem continuar existindo.
 */
final class LegacyHookRegistry
{
    /** @var string */
    private $pluginPath;

    /** @var array<int, array{path:string, required:bool}> */
    private $legacyFiles;

    /**
     * @param string $pluginPath  Diretório absoluto do plugin.
     * @param array<int, array{path:string, required:bool}> $legacyFiles Manifesto de arquivos legados.
     */
    public function __construct(string $pluginPath, array $legacyFiles)
    {
        $this->pluginPath = rtrim($pluginPath, '/\\') . DIRECTORY_SEPARATOR;
        $this->legacyFiles = $legacyFiles;
    }

    /**
     * Carrega arquivos legados na ordem definida em config/legacy-files.php.
     */
    public function register(): void
    {
        foreach ($this->legacyFiles as $legacyFile) {
            SafeRequire::file(
                $this->pluginPath . $legacyFile['path'],
                (bool) $legacyFile['required']
            );
        }
    }
}
