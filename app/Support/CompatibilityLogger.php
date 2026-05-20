<?php

declare(strict_types=1);

namespace Acme\AccountControl\Support;

/**
 * Logger temporário para a fase final de remoção do legado.
 *
 * Objetivo:
 * Registrar, somente em ambiente de debug, quais partes da camada de
 * compatibilidade ainda são carregadas durante o bootstrap do WordPress.
 *
 * Motivo:
 * Antes de remover arquivos procedurais restantes, precisamos observar uso real
 * sem alterar hooks públicos, shortcodes, endpoints REST, AJAX, cron ou funções
 * globais `acme_*` que podem ser consumidas por integrações externas.
 *
 * Remoção futura:
 * Esta classe deve ser removida quando a compatibilidade legada deixar de ser
 * necessária e os relatórios confirmarem que não há uso ativo dos arquivos
 * procedurais monitorados.
 */
final class CompatibilityLogger
{
    /**
     * Registra uma mensagem de compatibilidade apenas quando WP_DEBUG estiver ativo.
     *
     * @param string $message Mensagem objetiva sobre o legado observado.
     */
    public static function log(string $message): void
    {
        if (!defined('WP_DEBUG') || WP_DEBUG !== true) {
            return;
        }

        error_log('[ACME Compatibility] ' . $message);
    }


    /**
     * Registra o uso de uma função pública antiga mantida por compatibilidade.
     *
     * Objetivo:
     * Medir uso real de wrappers antes de qualquer remoção futura, sem alterar
     * assinatura, retorno ou comportamento da função antiga.
     *
     * @param string $functionName Nome da função global observada.
     * @param string $replacement  Implementação moderna recomendada.
     */
    public static function deprecatedFunctionUsed(string $functionName, string $replacement): void
    {
        self::log(sprintf(
            'Deprecated function used: %s. Replacement: %s',
            $functionName,
            $replacement
        ));
    }

    /**
     * Registra o carregamento de um arquivo legado ainda mantido por compatibilidade.
     *
     * @param string $relativePath Caminho relativo ao plugin para facilitar busca.
     */
    public static function legacyFileLoaded(string $relativePath): void
    {
        self::log('Legacy file loaded: ' . $relativePath);
    }
}
