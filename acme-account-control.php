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
require_once __DIR__ . '/app/Security/RequestGuard.php';
require_once __DIR__ . '/app/Support/CompatibilityLogger.php';
require_once __DIR__ . '/app/Views/View.php';
require_once __DIR__ . '/app/Controllers/Admin/AdminPageController.php';
require_once __DIR__ . '/app/Controllers/Admin/ApiConsumersAdminController.php';
require_once __DIR__ . '/app/Controllers/Frontend/ApiControlPanelController.php';
require_once __DIR__ . '/app/Models/ServiceRepository.php';
require_once __DIR__ . '/app/Models/WalletRepository.php';
require_once __DIR__ . '/app/Models/CreditTransactionRepository.php';
require_once __DIR__ . '/app/Models/DatabaseTransactionManager.php';
require_once __DIR__ . '/app/Models/CreditRepository.php';
require_once __DIR__ . '/app/Services/CreditTransactionService.php';
require_once __DIR__ . '/app/Services/CreditGrantService.php';
require_once __DIR__ . '/app/Models/ServiceRequestRepository.php';
require_once __DIR__ . '/app/Models/ApiAccessLogRepository.php';
require_once __DIR__ . '/app/Services/MasterAccessService.php';
require_once __DIR__ . '/app/Services/ApiResourceLinkService.php';
require_once __DIR__ . '/app/Controllers/Rest/InssResourceController.php';
require_once __DIR__ . '/app/Hooks/ActivationHooks.php';
require_once __DIR__ . '/app/Hooks/HookFileLoader.php';
require_once __DIR__ . '/app/Hooks/CompatibilityHooks.php';
require_once __DIR__ . '/app/Hooks/UserHooks.php';
require_once __DIR__ . '/app/Hooks/AdminHooks.php';
require_once __DIR__ . '/app/Hooks/FrontendHooks.php';
require_once __DIR__ . '/app/Hooks/RestApiHooks.php';
require_once __DIR__ . '/app/Hooks/ReportHooks.php';
require_once __DIR__ . '/app/Hooks/HookRegistrar.php';
require_once __DIR__ . '/app/Bootstrap.php';

(new Acme\AccountControl\Bootstrap(__FILE__))->run();
