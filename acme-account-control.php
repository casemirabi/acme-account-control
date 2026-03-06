<?php
/**
 * Plugin Name: ACME Account Control
 * Description: Controle hierárquico de usuários, créditos e integrações assíncronas.
 * Version: 1.2.1
 * Author: ACME
 * Text Domain: acme-account-control
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('ACME_ACC_FILE')) {
    define('ACME_ACC_FILE', __FILE__);
}
if (!defined('ACME_ACC_PATH')) {
    define('ACME_ACC_PATH', plugin_dir_path(__FILE__));
}
if (!defined('ACME_ACC_URL')) {
    define('ACME_ACC_URL', plugin_dir_url(__FILE__));
}


// ------------------------------------------------------------
// Configuração (chaves e endpoints)
// Dica: em produção, prefira definir no wp-config.php.
// Estas definições servem como fallback seguro no bootstrap.
// ------------------------------------------------------------
if (!defined('ACME_DEBUG')) define('ACME_DEBUG', false);
if (!defined('ACME_CLT_MOCK')) define('ACME_CLT_MOCK', true);
if (!defined('ACME_CLT_API_BASE')) define('ACME_CLT_API_BASE', '');
if (!defined('ACME_CLT_API_KEY')) define('ACME_CLT_API_KEY', '');
if (!defined('ACME_CLT_BRIDGE_URL')) define('ACME_CLT_BRIDGE_URL', '');
if (!defined('ACME_CLT_BRIDGE_KEY')) define('ACME_CLT_BRIDGE_KEY', '');
if (!defined('ACME_PB_INTERNAL_KEY')) define('ACME_PB_INTERNAL_KEY', '');
if (!defined('ACME_PB_BRIDGE_URL')) define('ACME_PB_BRIDGE_URL', '');
if (!defined('ACME_LOCAL_TEST_KEY')) define('ACME_LOCAL_TEST_KEY', '');
if (!defined('ACME_INSS_API_BASE')) define('ACME_INSS_API_BASE', 'https://teioemxjgepzvpcpevyi.supabase.co/functions/v1');
if (!defined('ACME_INSS_API_KEY')) define('ACME_INSS_API_KEY', defined('ACME_CLT_API_KEY') ? (string) ACME_CLT_API_KEY : '');

$acme_modules = [
    'includes/helpers.php',
    'includes/role-labels.php',
    'includes/users-module.php',
    'includes/services-module.php',
    'includes/credits-module.php',
    'includes/credits-engine.php',
    'includes/credits-distribution.php',
    'includes/credits-transfer.php',
    'includes/credits-transactions.php',
    'includes/credits-transactions-module.php',
    'includes/credits-contracts.php',
    'includes/credits-lots.php',
    'includes/credits-admin.php',
    'includes/credits-frontend.php',
    'includes/reports-export.php',
    'includes/reports.php',
    'includes/shortcodes_credits.php',
    'includes/clt-async.php',
    'includes/inss-async.php',
    'includes/pbank-async.php',
    'services/clt-provider.php',
];

foreach ($acme_modules as $module) {
    $path = ACME_ACC_PATH . $module;
    if (file_exists($path)) {
        require_once $path;
    }
}

function acme_account_control_activate()
{
    $activators = [
        'acme_users_activate',
        'acme_services_activate',
        'acme_credit_allowances_activate',
        'acme_credit_contracts_activate',
        'acme_credit_lots_activate',
        'acme_inss_activate',
        'acme_api_logs_activate',
    ];

    foreach ($activators as $fn) {
        if (function_exists($fn)) {
            $fn();
        }
    }
}
register_activation_hook(__FILE__, 'acme_account_control_activate');
