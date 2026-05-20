<?php
/**
 * Plugin Name: ACME Account Control
 * Description: Hierarquia Admin > Filho > Neto + cascata + bloqueio de login + gestão no front-end (Elementor)
 * Version: 1.2
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Arquivo principal do plugin.
 *
 * Objetivo:
 * Manter apenas o mínimo necessário para o WordPress reconhecer e inicializar o
 * plugin. Toda a lógica de boot foi movida para `app/Bootstrap.php`.
 *
 * Compatibilidade:
 * O nome deste arquivo foi preservado para não quebrar ativações existentes no
 * WordPress. As constantes e funções públicas legadas continuam sendo carregadas
 * pela camada de compatibilidade MVC.
 */
if (!defined('ACME_PLUGIN_FILE')) {
    define('ACME_PLUGIN_FILE', __FILE__);
}

require_once __DIR__ . '/app/Helpers/SafeRequire.php';
require_once __DIR__ . '/app/Services/VendorLoader.php';
require_once __DIR__ . '/app/Hooks/ActivationHooks.php';
require_once __DIR__ . '/app/Hooks/LegacyHookRegistry.php';
require_once __DIR__ . '/app/Bootstrap.php';

(new Acme\AccountControl\Bootstrap(__FILE__))->run();
