<?php

declare(strict_types=1);

namespace Acme\AccountControl\Helpers;

/**
 * Carregador seguro de arquivos PHP do plugin.
 *
 * Objetivo:
 * Evitar `require_once` espalhado pelo bootstrap e padronizar a forma como o
 * plugin reage a arquivos ausentes.
 *
 * Comportamento esperado:
 * - Arquivos obrigatórios ausentes geram erro controlado, pois o plugin não
 *   conseguiria funcionar corretamente.
 * - Arquivos opcionais ausentes são apenas registrados no log para facilitar
 *   diagnóstico sem derrubar o site.
 *
 * Integração WordPress:
 * Esse helper roda durante o carregamento do plugin, antes de muitos hooks do
 * WordPress. Por isso evitamos `wp_die()` aqui e usamos `trigger_error()` para
 * falhar de forma previsível quando necessário.
 */
final class SafeRequire
{
    /**
     * Inclui um arquivo PHP uma única vez, validando sua existência antes.
     *
     * @param string $absolutePath Caminho absoluto do arquivo a ser carregado.
     * @param bool   $required     Define se a ausência deve interromper o plugin.
     */
    public static function file(string $absolutePath, bool $required = true): void
    {
        if (file_exists($absolutePath)) {
            require_once $absolutePath;
            return;
        }

        error_log('[ACME] Missing include: ' . $absolutePath);

        if ($required) {
            trigger_error('ACME missing required file: ' . $absolutePath, E_USER_ERROR);
        }
    }
}
