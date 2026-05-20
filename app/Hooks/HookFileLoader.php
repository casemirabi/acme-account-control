<?php

declare(strict_types=1);

namespace Acme\AccountControl\Hooks;

use Acme\AccountControl\Helpers\SafeRequire;
use Acme\AccountControl\Support\CompatibilityLogger;

/**
 * Carrega arquivos de hooks ainda baseados em callbacks globais.
 *
 * Objetivo:
 * Concentrar, em um único ponto, todos os arquivos que ainda chamam `add_action`,
 * `add_filter`, `add_shortcode`, `register_rest_route`, AJAX ou admin_post.
 *
 * Motivo da implementação:
 * Parte do plugin ainda registra hooks no topo de arquivos PHP. Mover tudo de
 * uma vez para classes poderia quebrar comportamento público. Esta classe mantém
 * uma etapa segura: os carregamentos ficam centralizados e documentados enquanto
 * os callbacks públicos continuam disponíveis.
 *
 * Próxima etapa recomendada:
 * Migrar módulo por módulo para classes em `app/Controllers`, `app/Services`,
 * `app/Models` e `app/Hooks`, mantendo wrappers de compatibilidade quando nomes
 * públicos precisarem continuar existindo.
 */
final class HookFileLoader
{
    /** @var string */
    private $pluginPath;

    /** @var array<int, array{path:string, required:bool}> */
    private $hookFiles;

    /**
     * @param string $pluginPath  Diretório absoluto do plugin.
     * @param array<int, array{path:string, required:bool}> $hookFiles Manifesto de arquivos de hooks.
     */
    public function __construct(string $pluginPath, array $hookFiles)
    {
        $this->pluginPath = rtrim($pluginPath, '/\\') . DIRECTORY_SEPARATOR;
        $this->hookFiles = $hookFiles;
    }

    /**
     * Carrega arquivos de hooks na ordem recebida pelo grupo funcional atual.
     */
    public function register(): void
    {
        foreach ($this->hookFiles as $hookFile) {
            $relativePath = (string) $hookFile['path'];

            // Log temporário e opcional para descobrir quais arquivos procedurais
            // ainda são carregados durante a fase final de remoção do legado.
            CompatibilityLogger::legacyFileLoaded($relativePath);

            SafeRequire::file(
                $this->pluginPath . $relativePath,
                (bool) $hookFile['required']
            );
        }
    }
}
