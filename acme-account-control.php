<?php
/*
Plugin Name: ACME Account Control
Description: Hierarquia Admin > Filho > Neto + cascata + bloqueio de login + gestão no front-end (Elementor)
Version: 1.2
*/

if (!defined('ABSPATH')) exit;

/**
 * ============================================================
 * CONFIG / CONSTANTES (SAFE)
 * ============================================================
 */

if (!defined('ACME_ACC_PATH')) define('ACME_ACC_PATH', plugin_dir_path(__FILE__));
if (!defined('ACME_ACC_URL'))  define('ACME_ACC_URL', plugin_dir_url(__FILE__));

if (!defined('ACME_DEBUG')) define('ACME_DEBUG', false);

// Modo simulação (true = não chama API real)
if (!defined('ACME_CLT_MOCK')) define('ACME_CLT_MOCK', true);

// Token fake apenas para log
if (!defined('ACME_CLT_MOCK_TOKEN')) define('ACME_CLT_MOCK_TOKEN', 'mock_token_local');

// Provider antigo (Supabase) fallback
if (!defined('ACME_CLT_API_BASE')) define('ACME_CLT_API_BASE', 'https://teioemxjgepzvpcpevyi.supabase.co/functions/v1');
if (!defined('ACME_CLT_API_KEY'))  define('ACME_CLT_API_KEY',  'mcs_LaT9SiuPPJz5rpBZBmBbsJfEuY3XFtcNV9vNou4xWJ6q4l1p');

// Chave interna (usada em integrações / bridge)
if (!defined('ACME_PB_INTERNAL_KEY')) {define('ACME_PB_INTERNAL_KEY', 'MINHA_CHAVE_SECRETA_123');}

/**
 * Bridge do CLT Async (acme-services enqueue)
 * - Em produção, NÃO use 127.0.0.1 (isso aponta pro servidor, não pra sua máquina).
 * - Se você ainda não tiver o endpoint público do enqueue, deixe vazio para não usar bridge.
 */
if (!defined('ACME_CLT_BRIDGE_URL')) {
  // Exemplo (ajuste pro seu endpoint REAL):
  // define('ACME_CLT_BRIDGE_URL', 'https://api.maiscorban.net/pb/credito-privado/enqueue');
  define('ACME_CLT_BRIDGE_URL', 'https://api.maiscorban.net/pb/credito-privado/enqueue');
}

if (!defined('ACME_CLT_BRIDGE_KEY')) {
  // se não tiver key em produção, pode deixar vazio
  define('ACME_CLT_BRIDGE_KEY', 'f54oiuwqhbsncmn487924367jhf');
}

/**
 * Bridge do PB (consulta)
 */
if (!defined('ACME_PB_BRIDGE_URL')) {define('ACME_PB_BRIDGE_URL', 'https://api.maiscorban.net/pb/credito-privado/consulta');}

/**
 * ============================================================
 * Helper: include seguro para evitar fatal
 * ============================================================
 */
if (!function_exists('acme_safe_require')) {
  function acme_safe_require(string $file, bool $required = true): void
  {
    if (file_exists($file)) {
      require_once $file;
      return;
    }
    // loga pra você enxergar o arquivo faltando
    error_log('[ACME] Missing include: ' . $file);

    // se for required=true, a gente ainda derruba (pra não mascarar bug crítico).
    // eu deixei required=true só pros principais. Pros opcionais, required=false.
    if ($required) {
      // mensagem amigável pro admin (opcional)
      // wp_die('ACME: Arquivo ausente: ' . esc_html($file));
      // como isso pode rodar cedo, só dispara fatal "controlado":
      trigger_error('ACME missing required file: ' . $file, E_USER_ERROR);
    }
  }
}

/**
 * ============================================================
 * Composer autoload (Dompdf etc)
 * ============================================================
 */
$acme_autoload = ACME_ACC_PATH . 'vendor/autoload.php';
if (file_exists($acme_autoload)) {
  require_once $acme_autoload;
} else {
  // não necessariamente fatal (depende se você usa dompdf nesse site)
  error_log('[ACME] vendor/autoload.php not found: ' . $acme_autoload);
}

/**
 * ============================================================
 * INCLUDES (ordem original, mas seguro)
 * ============================================================
 */
acme_safe_require(ACME_ACC_PATH . 'includes/helpers.php', true);
acme_safe_require(ACME_ACC_PATH . 'includes/users-module.php', true);
acme_safe_require(ACME_ACC_PATH . 'includes/services-module.php', true);
acme_safe_require(ACME_ACC_PATH . 'services/clt-provider.php', true);



acme_safe_require(__DIR__ . '/includes/credits-engine.php', true);
acme_safe_require(__DIR__ . '/includes/credits-module.php', true);

acme_safe_require(__DIR__ . '/includes/shortcodes_credits.php', false);
acme_safe_require(__DIR__ . '/includes/credits-admin.php', false);
acme_safe_require(__DIR__ . '/includes/credits-frontend.php', false);

acme_safe_require(__DIR__ . '/includes/credits-transactions.php', false);
acme_safe_require(__DIR__ . '/includes/credits-distribution.php', false);
acme_safe_require(__DIR__ . '/includes/credits-transfer.php', false);

acme_safe_require(__DIR__ . '/includes/credits-contracts.php', false);
acme_safe_require(__DIR__ . '/includes/credits-lots.php', false);

acme_safe_require(__DIR__ . '/includes/role-labels.php', false);

/**
 * ⚠️ IMPORTANTÍSSIMO EM PRODUÇÃO:
 * Verifique se o nome do arquivo é exatamente "clt-async.php" no servidor (case-sensitive).
 */
acme_safe_require(ACME_ACC_PATH . 'includes/clt-async.php', true);

// Se você tiver esses arquivos também em produção, pode habilitar:
acme_safe_require(ACME_ACC_PATH . 'includes/inss-async.php', false);
acme_safe_require(ACME_ACC_PATH . 'includes/pbank-async.php', false);

acme_safe_require(__DIR__ . '/includes/reports-export.php', false);
acme_safe_require(__DIR__ . '/includes/reports.php', false);

// Se esses existirem em produção:
//acme_safe_require(ACME_ACC_PATH . 'pbank/admin_presenca_test.php', false);
//acme_safe_require(ACME_ACC_PATH . 'pbank/presenca_client.php', false);

/**
 * ============================================================
 * Activation hooks
 * ============================================================
 */
register_activation_hook(__FILE__, function () {
  if (function_exists('acme_services_activate')) acme_services_activate();
  if (function_exists('acme_credit_allowances_activate')) acme_credit_allowances_activate();
  if (function_exists('acme_credit_contracts_activate')) acme_credit_contracts_activate();
  if (function_exists('acme_credit_lots_activate')) acme_credit_lots_activate();

  if (function_exists('acme_users_activate')) acme_users_activate();
  if (function_exists('acme_api_logs_activate')) acme_api_logs_activate();
  if (function_exists('acme_clt_activate')) acme_clt_activate();
  if (function_exists('acme_inss_activate')) acme_inss_activate();
});