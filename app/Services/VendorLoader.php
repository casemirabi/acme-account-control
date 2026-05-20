<?php

declare(strict_types=1);

namespace Acme\AccountControl\Services;

use Acme\AccountControl\Helpers\SafeRequire;

/**
 * Responsável por carregar dependências externas do plugin.
 *
 * Objetivo:
 * Isolar autoloaders e bibliotecas vendorizadas para que o bootstrap principal
 * não tenha conhecimento de Dompdf, TCPDF ou Composer.
 *
 * RISCO:
 * Bibliotecas como TCPDF declaram classes globais. Por isso usamos `require_once`
 * e verificamos existência do arquivo antes de carregar para evitar fatal errors
 * quando a instalação está incompleta.
 */
final class VendorLoader
{
    /** @var string */
    private $pluginPath;

    public function __construct(string $pluginPath)
    {
        $this->pluginPath = rtrim($pluginPath, '/\\') . DIRECTORY_SEPARATOR;
    }

    /**
     * Carrega dependências opcionais e obrigatórias de forma previsível.
     */
    public function load(): void
    {
        SafeRequire::file($this->pluginPath . 'vendor/autoload.php', false);
        SafeRequire::file($this->pluginPath . 'lib/tcpdf/tcpdf.php', false);
    }
}
