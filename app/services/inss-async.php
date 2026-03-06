<?php
if (!defined('ABSPATH')) exit;

/**
 * ============================================================
 * ACME INSS — Extrato Online + Queue
 * - POST /wp-json/acme/v1/api-inss-extrato-online
 * - POST /wp-json/acme/v1/api-inss-queue-request
 * - GET  /wp-json/acme/v1/api-inss-status?request_id=...
 * - POST /wp-json/acme/v1/inss-webhook
 *
 * Observações:
 * - Extrato Online só 09:00–18:00 (Brasília)
 * - PDF vem em base64
 * - 1 crédito (service_slug = "extrato") por consulta bem-sucedida
 * - Queue reserva crédito no agendamento; no webhook, se falhar, estorna.
 * ============================================================
 */

if (!defined('ACME_INSS_API_BASE')) define('ACME_INSS_API_BASE', 'https://teioemxjgepzvpcpevyi.supabase.co/functions/v1');

add_action('rest_api_init', function () {

  register_rest_route('acme/v1', '/api-inss-extrato-online', [
    'methods' => 'POST',
    'callback' => 'acme_api_inss_extrato_online',
    'permission_callback' => '__return_true',
  ]);

  register_rest_route('acme/v1', '/api-inss-queue-request', [
    'methods' => 'POST',
    'callback' => 'acme_api_inss_queue_request',
    'permission_callback' => '__return_true',
  ]);

  register_rest_route('acme/v1', '/api-inss-status', [
    'methods' => 'GET',
    'callback' => 'acme_api_inss_status',
    'permission_callback' => '__return_true',
  ]);

  register_rest_route('acme/v1', '/inss-webhook', [
    'methods' => 'POST',
    'callback' => 'acme_api_inss_webhook',
    'permission_callback' => '__return_true',
  ]);
});

function acme_inss_requests_table(): string {
  global $wpdb;
  return $wpdb->prefix . 'inss_requests';
}

/** Criação da tabela INSS */
function acme_inss_activate() {
  global $wpdb;
  require_once ABSPATH . 'wp-admin/includes/upgrade.php';

  $t = acme_inss_requests_table();
  $charset = $wpdb->get_charset_collate();

  $sql = "CREATE TABLE {$t} (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    request_id VARCHAR(64) NOT NULL,            -- inss_xxx (interno)
    provider_request_id VARCHAR(80) NULL,       -- uuid retornado pelo /api-queue-request
    beneficio_hash CHAR(64) NOT NULL,
    beneficio_masked VARCHAR(20) NOT NULL,
    webhook_url VARCHAR(255) NULL,
    kind ENUM('online','queue') NOT NULL DEFAULT 'online',
    status ENUM('pending','completed','failed') NOT NULL DEFAULT 'pending',
    response_json LONGTEXT NULL,
    error_code VARCHAR(60) NULL,
    error_message VARCHAR(255) NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    completed_at DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_request_id (request_id),
    KEY idx_user (user_id),
    KEY idx_status (status),
    KEY idx_provider (provider_request_id)
  ) {$charset};";

  dbDelta($sql);
}

/** Horário Brasília 09:00–18:00 */
function acme_inss_is_open_now(): bool {
  $tz = new DateTimeZone('America/Sao_Paulo');
  $now = new DateTime('now', $tz);
  $h = (int)$now->format('H');
  return ($h >= 9 && $h < 18);
}

function acme_inss_mask_beneficio(string $nb): string {
  $nb = preg_replace('/\D+/', '', $nb);
  if (strlen($nb) <= 4) return str_repeat('*', max(0, strlen($nb)));
  return str_repeat('*', strlen($nb) - 4) . substr($nb, -4);
}

function acme_inss_new_request_id(): string {
  return 'inss_' . wp_generate_password(18, false, false);
}

function acme_inss_webhook_url(): string {
  return rest_url('acme/v1/inss-webhook');
}

/** HTTP helper */
function acme_inss_http_post_json(string $path, array $body): array {
  $url = rtrim(ACME_INSS_API_BASE, '/') . '/' . ltrim($path, '/');

  $args = [
    'timeout' => 30,
    'headers' => [
      'Content-Type' => 'application/json',
      'x-api-key' => ACME_INSS_API_KEY,
    ],
    'body' => wp_json_encode($body, JSON_UNESCAPED_UNICODE),
  ];

  $res = wp_remote_post($url, $args);

  if (is_wp_error($res)) {
    return ['ok' => false, 'http_error' => $res->get_error_message()];
  }

  $code = (int) wp_remote_retrieve_response_code($res);
  $raw  = (string) wp_remote_retrieve_body($res);
  $json = json_decode($raw, true);

  if (!is_array($json)) $json = ['raw' => $raw];

  return ['ok' => ($code >= 200 && $code < 300), 'http_code' => $code, 'json' => $json];
}

/** Estorno (se a fila falhar depois de reservar crédito) */
function acme_inss_refund_credit_by_request_id(string $request_id): void {
  global $wpdb;

  $txT   = $wpdb->prefix . 'credit_transactions';
  $lotsT = $wpdb->prefix . 'credit_lots';
  $ctT   = $wpdb->prefix . 'credit_contracts';

  $tx = $wpdb->get_row($wpdb->prepare(
    "SELECT * FROM {$txT} WHERE request_id=%s AND type='debit' AND status='success' ORDER BY id DESC LIMIT 1",
    $request_id
  ), ARRAY_A);

  if (!$tx) return;

  $meta = [];
  if (!empty($tx['meta'])) {
    $m = json_decode($tx['meta'], true);
    if (is_array($m)) $meta = $m;
  }

  $lot_id = (int)($meta['lot_id'] ?? 0);
  $amount = max(1, (int)($tx['credits'] ?? 1));

  if ($lot_id <= 0) return;

  $wpdb->query('START TRANSACTION');
  try {
    $lot = $wpdb->get_row($wpdb->prepare(
      "SELECT id, contract_id, credits_used FROM {$lotsT} WHERE id=%d LIMIT 1 FOR UPDATE",
      $lot_id
    ), ARRAY_A);

    if ($lot) {
      $used = max(0, (int)$lot['credits_used'] - $amount);
      $ok = $wpdb->update($lotsT, ['credits_used' => $used, 'updated_at' => current_time('mysql')], ['id' => $lot_id]);
      if ($ok === false) throw new Exception($wpdb->last_error);

      $contract_id = (int)($lot['contract_id'] ?? 0);
      if ($contract_id > 0) {
        $c = $wpdb->get_row($wpdb->prepare(
          "SELECT id, credits_used FROM {$ctT} WHERE id=%d LIMIT 1 FOR UPDATE",
          $contract_id
        ), ARRAY_A);

        if ($c) {
          $c_used = max(0, (int)$c['credits_used'] - $amount);
          $okc = $wpdb->update($ctT, ['credits_used' => $c_used, 'updated_at' => current_time('mysql')], ['id' => $contract_id]);
          if ($okc === false) throw new Exception($wpdb->last_error);
        }
      }

      // Log de estorno
      $wpdb->insert($txT, [
        'user_id' => (int)$tx['user_id'],
        'service_id' => (int)$tx['service_id'],
        'service_slug' => $tx['service_slug'],
        'service_name' => $tx['service_name'],
        'type' => 'credit',
        'credits' => $amount,
        'status' => 'success',
        'attempts' => 1,
        'request_id' => $request_id,
        'actor_user_id' => get_current_user_id(),
        'notes' => 'Estorno automático (INSS queue falhou)',
        'meta' => wp_json_encode(['refund_of_tx' => (int)$tx['id'], 'lot_id' => $lot_id]),
        'created_at' => current_time('mysql'),
        'wallet_total_before' => 0,
        'wallet_used_before' => 0,
        'wallet_total_after' => 0,
        'wallet_used_after' => 0,
      ]);
    }

    $wpdb->query('COMMIT');
  } catch (Exception $e) {
    $wpdb->query('ROLLBACK');
  }
}

/** POST /api-inss-extrato-online */
function acme_api_inss_extrato_online(WP_REST_Request $req) {
  global $wpdb;

  $user_id = get_current_user_id();
  $beneficio = (string)($req->get_param('beneficio') ?? '');
  $beneficio = preg_replace('/\D+/', '', $beneficio);

  if (!$beneficio) return new WP_REST_Response(['success' => false, 'code' => 'MISSING_BENEFICIO', 'error' => 'beneficio obrigatório'], 400);

  if (!acme_inss_is_open_now()) {
    return new WP_REST_Response([
      'success' => false,
      'code' => 'OUT_OF_BUSINESS_HOURS',
      'error' => 'Fora do horário (09:00–18:00 Brasília). Use /api-inss-queue-request para agendar.'
    ], 403);
  }

  // 1 crédito por sucesso (service_slug = extrato)
  if (!acme_user_has_credit($user_id, 'extrato')) {
    return new WP_REST_Response(['success' => false, 'code' => 'NO_CREDITS', 'error' => 'Sem créditos'], 402);
  }

  $rid = acme_inss_new_request_id();
  $t = acme_inss_requests_table();
  $now = current_time('mysql');

  $wpdb->insert($t, [
    'user_id' => (int)$user_id,
    'request_id' => $rid,
    'provider_request_id' => null,
    'beneficio_hash' => hash('sha256', $beneficio),
    'beneficio_masked' => acme_inss_mask_beneficio($beneficio),
    'webhook_url' => null,
    'kind' => 'online',
    'status' => 'pending',
    'created_at' => $now,
    'updated_at' => $now,
  ]);

  $http = acme_inss_http_post_json('/api-extrato-online', ['beneficio' => $beneficio]);

  if (!$http['ok']) {
    $wpdb->update($t, [
      'status' => 'failed',
      'error_code' => 'HTTP_ERROR',
      'error_message' => $http['http_error'] ?? ('HTTP ' . ($http['http_code'] ?? 'ERR')),
      'response_json' => wp_json_encode($http['json'] ?? [], JSON_UNESCAPED_UNICODE),
      'updated_at' => current_time('mysql'),
      'completed_at' => current_time('mysql'),
    ], ['request_id' => $rid]);

    return new WP_REST_Response(['success' => false, 'code' => 'PROVIDER_ERROR', 'error' => 'Falha ao consultar fornecedor', 'request_id' => $rid], 502);
  }

  $payload = $http['json'];
  $ok = !empty($payload['success']);

  if (!$ok) {
    $wpdb->update($t, [
      'status' => 'failed',
      'error_code' => (string)($payload['code'] ?? 'PROVIDER_FAIL'),
      'error_message' => (string)($payload['error'] ?? 'Falha'),
      'response_json' => wp_json_encode($payload, JSON_UNESCAPED_UNICODE),
      'updated_at' => current_time('mysql'),
      'completed_at' => current_time('mysql'),
    ], ['request_id' => $rid]);

    return new WP_REST_Response(['success' => false, 'code' => ($payload['code'] ?? 'PROVIDER_FAIL'), 'error' => ($payload['error'] ?? 'Falha'), 'request_id' => $rid], 200);
  }

  // Debita crédito SOMENTE no sucesso
  $debit = acme_consume_credit((int)$user_id, 'extrato', 1, $rid, 'Extrato');

  if (empty($debit['ok'])) {
    // Se não conseguiu debitar, marca falha interna
    $wpdb->update($t, [
      'status' => 'failed',
      'error_code' => 'CREDIT_DEBIT_FAIL',
      'error_message' => (string)($debit['error'] ?? 'Falha ao debitar'),
      'response_json' => wp_json_encode($payload, JSON_UNESCAPED_UNICODE),
      'updated_at' => current_time('mysql'),
      'completed_at' => current_time('mysql'),
    ], ['request_id' => $rid]);

    return new WP_REST_Response(['success' => false, 'code' => 'CREDIT_DEBIT_FAIL', 'error' => 'Falha ao debitar crédito', 'request_id' => $rid], 500);
  }

  $wpdb->update($t, [
    'status' => 'completed',
    'response_json' => wp_json_encode($payload, JSON_UNESCAPED_UNICODE),
    'updated_at' => current_time('mysql'),
    'completed_at' => current_time('mysql'),
    'error_code' => null,
    'error_message' => null,
  ], ['request_id' => $rid]);

  // retorna o payload (já vem com pdf_base64)
  $payload['request_id'] = $rid;
  return new WP_REST_Response($payload, 200);
}

/** POST /api-inss-queue-request */
function acme_api_inss_queue_request(WP_REST_Request $req) {
  global $wpdb;

  $user_id = get_current_user_id();
  $beneficio = (string)($req->get_param('beneficio') ?? '');
  $beneficio = preg_replace('/\D+/', '', $beneficio);

  if (!$beneficio) return new WP_REST_Response(['success' => false, 'code' => 'MISSING_BENEFICIO', 'error' => 'beneficio obrigatório'], 400);

  if (!acme_user_has_credit($user_id, 'extrato')) {
    return new WP_REST_Response(['success' => false, 'code' => 'NO_CREDITS', 'error' => 'Sem créditos'], 402);
  }

  $rid = acme_inss_new_request_id();
  $t = acme_inss_requests_table();
  $now = current_time('mysql');

  // Reserva crédito já no agendamento (como a doc pede)
  $debit = acme_consume_credit((int)$user_id, 'extrato', 1, $rid, 'Extrato');
  if (empty($debit['ok'])) {
    return new WP_REST_Response(['success' => false, 'code' => 'CREDIT_DEBIT_FAIL', 'error' => ($debit['error'] ?? 'Falha ao reservar crédito')], 500);
  }

  $webhook = acme_inss_webhook_url();

  $wpdb->insert($t, [
    'user_id' => (int)$user_id,
    'request_id' => $rid,
    'provider_request_id' => null,
    'beneficio_hash' => hash('sha256', $beneficio),
    'beneficio_masked' => acme_inss_mask_beneficio($beneficio),
    'webhook_url' => $webhook,
    'kind' => 'queue',
    'status' => 'pending',
    'created_at' => $now,
    'updated_at' => $now,
  ]);

  $http = acme_inss_http_post_json('/api-queue-request', [
    'beneficio' => $beneficio,
    'webhook_url' => $webhook,
  ]);

  if (!$http['ok'] || empty($http['json']['success'])) {
    // se falhou agendar, estorna imediatamente (não ficou “reservado” de verdade)
    acme_inss_refund_credit_by_request_id($rid);

    $wpdb->update($t, [
      'status' => 'failed',
      'error_code' => (string)($http['json']['code'] ?? 'QUEUE_FAIL'),
      'error_message' => (string)($http['json']['error'] ?? $http['http_error'] ?? 'Falha ao agendar'),
      'response_json' => wp_json_encode($http['json'] ?? [], JSON_UNESCAPED_UNICODE),
      'updated_at' => current_time('mysql'),
      'completed_at' => current_time('mysql'),
    ], ['request_id' => $rid]);

    return new WP_REST_Response(['success' => false, 'code' => 'QUEUE_FAIL', 'error' => 'Falha ao adicionar na fila', 'request_id' => $rid], 502);
  }

  $provider_id = (string)($http['json']['request_id'] ?? '');

  $wpdb->update($t, [
    'provider_request_id' => $provider_id ?: null,
    'response_json' => wp_json_encode($http['json'], JSON_UNESCAPED_UNICODE),
    'updated_at' => current_time('mysql'),
  ], ['request_id' => $rid]);

  $out = $http['json'];
  $out['internal_request_id'] = $rid;
  return new WP_REST_Response($out, 200);
}

/** GET /api-inss-status */
function acme_api_inss_status(WP_REST_Request $req) {
  global $wpdb;
  $rid = (string)($req->get_param('request_id') ?? '');
  if (!$rid) return new WP_REST_Response(['success' => false, 'code' => 'MISSING_REQUEST_ID', 'error' => 'request_id obrigatório'], 400);

  $t = acme_inss_requests_table();
  $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t} WHERE request_id=%s LIMIT 1", $rid), ARRAY_A);

  if (!$row) return new WP_REST_Response(['success' => false, 'code' => 'NOT_FOUND', 'error' => 'Requisição não encontrada'], 404);

  $payload = [
    'success' => true,
    'request_id' => $row['request_id'],
    'provider_request_id' => $row['provider_request_id'],
    'kind' => $row['kind'],
    'status' => $row['status'],
    'error' => $row['error_message'],
    'code' => $row['error_code'],
  ];

  if (!empty($row['response_json'])) {
    $j = json_decode($row['response_json'], true);
    if (is_array($j)) $payload['provider_response'] = $j;
  }

  return new WP_REST_Response($payload, 200);
}

/** POST /inss-webhook (resultado da fila) */
function acme_api_inss_webhook(WP_REST_Request $req) {
  global $wpdb;

  // Aceita tanto internal_request_id quanto provider_request_id, pra ser robusto
  $internal = (string)($req->get_param('internal_request_id') ?? $req->get_param('request_id') ?? '');
  $provider = (string)($req->get_param('provider_request_id') ?? '');

  $t = acme_inss_requests_table();

  $row = null;
  if ($internal) {
    $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t} WHERE request_id=%s LIMIT 1", $internal), ARRAY_A);
  }
  if (!$row && $provider) {
    $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t} WHERE provider_request_id=%s LIMIT 1", $provider), ARRAY_A);
  }

  if (!$row) return new WP_REST_Response(['success' => false, 'code' => 'NOT_FOUND', 'error' => 'Request não encontrada'], 404);

  $payload = $req->get_json_params();
  if (!is_array($payload)) $payload = [];

  $ok = !empty($payload['success']);
  $rid = (string)$row['request_id'];

  if ($ok) {
    $wpdb->update($t, [
      'status' => 'completed',
      'response_json' => wp_json_encode($payload, JSON_UNESCAPED_UNICODE),
      'error_code' => null,
      'error_message' => null,
      'updated_at' => current_time('mysql'),
      'completed_at' => current_time('mysql'),
    ], ['request_id' => $rid]);

    return new WP_REST_Response(['success' => true, 'request_id' => $rid, 'status' => 'completed'], 200);
  }

  // Falhou: estorna (porque na fila o crédito foi reservado no agendamento)
  acme_inss_refund_credit_by_request_id($rid);

  $wpdb->update($t, [
    'status' => 'failed',
    'response_json' => wp_json_encode($payload, JSON_UNESCAPED_UNICODE),
    'error_code' => (string)($payload['code'] ?? 'FAILED'),
    'error_message' => (string)($payload['error'] ?? 'Falhou'),
    'updated_at' => current_time('mysql'),
    'completed_at' => current_time('mysql'),
  ], ['request_id' => $rid]);

  return new WP_REST_Response(['success' => false, 'request_id' => $rid, 'status' => 'failed'], 200);
}
