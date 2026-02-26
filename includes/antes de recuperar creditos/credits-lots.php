<?php
if (!defined('ABSPATH')) exit;

function acme_table_credit_lots(): string {
  global $wpdb;
  return $wpdb->prefix . 'credit_lots';
}

/**
 * Lotes de crédito:
 * - subscription: tem expires_at e contract_id
 * - full: expires_at NULL
 * - transfer: criado quando Filho distribui para Neto (preserva expires_at do lote de origem)
 */
function acme_credit_lots_activate() {
  global $wpdb;
  require_once ABSPATH . 'wp-admin/includes/upgrade.php';

  $t = acme_table_credit_lots();
  $charset = $wpdb->get_charset_collate();

  $sql = "CREATE TABLE {$t} (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    owner_user_id BIGINT UNSIGNED NOT NULL,
    service_id BIGINT UNSIGNED NOT NULL,
    source VARCHAR(20) NOT NULL,
    contract_id BIGINT UNSIGNED NULL,
    credits_total INT NOT NULL DEFAULT 0,
    credits_used INT NOT NULL DEFAULT 0,
    expires_at DATETIME NULL,
    meta LONGTEXT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_owner (owner_user_id),
    KEY idx_service (service_id),
    KEY idx_expires (expires_at),
    KEY idx_contract (contract_id)
  ) {$charset};";

  dbDelta($sql);
}

function acme_lots_available_sum(int $user_id, int $service_id): int {
  global $wpdb;
  $t = acme_table_credit_lots();
  $now = current_time('mysql');

  $sum = (int) $wpdb->get_var($wpdb->prepare(
    "SELECT COALESCE(SUM(GREATEST(credits_total - credits_used, 0)), 0)
     FROM {$t}
     WHERE owner_user_id=%d AND service_id=%d
       AND (expires_at IS NULL OR expires_at >= %s)",
    $user_id, $service_id, $now
  ));

  return max(0, $sum);
}

function acme_lot_create(int $owner_user_id, int $service_id, string $source, int $credits_total, ?string $expires_at, ?int $contract_id = null, array $meta = []) {
  global $wpdb;
  $t = acme_table_credit_lots();
  $now = current_time('mysql');

  $ok = $wpdb->insert($t, [
    'owner_user_id' => $owner_user_id,
    'service_id'    => $service_id,
    'source'        => $source,
    'contract_id'   => $contract_id ?: null,
    'credits_total' => $credits_total,
    'credits_used'  => 0,
    'expires_at'    => $expires_at ?: null,
    'meta'          => !empty($meta) ? wp_json_encode($meta) : null,
    'created_at'    => $now,
    'updated_at'    => $now,
  ]);

  if (!$ok) {
    return new WP_Error('acme_db', 'Erro ao criar lote: ' . ($wpdb->last_error ?: 'db insert failed'));
  }

  return ['success' => true, 'lot_id' => (int) $wpdb->insert_id];
}

/**
 * Transferência Filho -> Neto preservando validade lote-a-lote.
 * Debita do Filho "usando" (credits_used) nos lotes de origem e cria lotes no Neto.
 */
function acme_lots_transfer_child_to_grandchild(int $child_id, int $grandchild_id, int $service_id, int $credits, ?string $notes = null, array $meta = []) {
  if ($credits <= 0) return new WP_Error('acme_invalid_amount', 'Quantidade inválida.');
  if ($credits > 1000) return new WP_Error('acme_limit', 'Máximo por operação: 1000 créditos.');

  // valida vínculo (precisa do módulo users/distribution)
  if (!function_exists('acme_can_current_user_grant_to') || !acme_can_current_user_grant_to($grandchild_id)) {
    return new WP_Error('acme_forbidden', 'Você só pode distribuir para seus próprios Netos.');
  }

  global $wpdb;
  $lotsT = acme_table_credit_lots();
  $now = current_time('mysql');

  $wpdb->query('START TRANSACTION');

  try {
    // Lock nos lotes do Filho, ordenando: expira primeiro, depois mais antigo. NULL (full) por último.
    $rows = $wpdb->get_results($wpdb->prepare(
      "SELECT id, credits_total, credits_used, expires_at, source, contract_id
       FROM {$lotsT}
       WHERE owner_user_id=%d AND service_id=%d
         AND (expires_at IS NULL OR expires_at >= %s)
         AND (credits_total - credits_used) > 0
       ORDER BY (expires_at IS NULL) ASC, expires_at ASC, id ASC
       FOR UPDATE",
      $child_id, $service_id, $now
    ));

    $available = 0;
    foreach ($rows as $r) $available += max(0, ((int)$r->credits_total - (int)$r->credits_used));
    if ($credits > $available) throw new Exception('Saldo insuficiente no Master para distribuir.');

    $remaining = $credits;

    foreach ($rows as $r) {
      if ($remaining <= 0) break;

      $lot_avail = max(0, ((int)$r->credits_total - (int)$r->credits_used));
      if ($lot_avail <= 0) continue;

      $take = min($lot_avail, $remaining);
      $new_used = (int)$r->credits_used + $take;

      // debita do lote do Filho (reserva/transferiu)
      $ok = $wpdb->update($lotsT, [
        'credits_used' => $new_used,
        'updated_at'   => $now,
      ], ['id' => (int)$r->id]);

      if ($ok === false) throw new Exception('Erro ao debitar lote do Master: ' . ($wpdb->last_error ?: 'db update failed'));

      // cria lote no Neto preservando expiração do lote origem
      $resLot = acme_lot_create(
        $grandchild_id,
        $service_id,
        'transfer',
        $take,
        $r->expires_at ?: null,
        $r->contract_id ? (int) $r->contract_id : null,
        array_merge($meta, [
          'from_child'   => $child_id,
          'origin_lot'   => (int)$r->id,
          'origin_source'=> (string)$r->source,
          'origin_contract_id' => $r->contract_id ? (int)$r->contract_id : null,
        ])
      );
      if (is_wp_error($resLot)) throw new Exception($resLot->get_error_message());

      $remaining -= $take;
    }

    // Log opcional em wp_credit_transactions, se existir logger
    if (function_exists('acme_credits_tx_log')) {
      $notes_out = $notes ? ("Distribuição para Sub-Login #{$grandchild_id} - {$notes}") : "Distribuição para Sub-Login #{$grandchild_id}";
      acme_credits_tx_log([
        'user_id'       => $child_id,
        'actor_user_id' => $child_id,
        'service_id'    => $service_id,
        'type'          => 'debit',
        'credits'       => $credits,
        'status'        => 'success',
        'attempts'      => 1,
        'notes'         => $notes_out,
        'meta'          => wp_json_encode(array_merge($meta, ['transfer_to' => $grandchild_id])),
        'created_at'    => $now,
      ]);

      $notes_in = $notes ? ("Recebido do Master #{$child_id} - {$notes}") : "Recebido do Master #{$child_id}";
      acme_credits_tx_log([
        'user_id'       => $grandchild_id,
        'actor_user_id' => $child_id,
        'service_id'    => $service_id,
        'type'          => 'credit',
        'credits'       => $credits,
        'status'        => 'success',
        'attempts'      => 1,
        'notes'         => $notes_in,
        'meta'          => wp_json_encode(array_merge($meta, ['transfer_from' => $child_id])),
        'created_at'    => $now,
      ]);
    }

    $wpdb->query('COMMIT');
    return ['success' => true];

  } catch (Exception $e) {
    $wpdb->query('ROLLBACK');
    return new WP_Error('acme_transfer_failed', $e->getMessage());
  }
}



if (!defined('ABSPATH')) exit;

if (!function_exists('acme_table_credit_transactions')) {
  function acme_table_credit_transactions(): string {
    global $wpdb;
    return $wpdb->prefix . 'credit_transactions';
  }
}

/**
 * Logger padrão para wp_credit_transactions (compatível com sua tabela atual).
 * Preenche apenas colunas existentes.
 * Logar a consulta CLT
 */
if (!function_exists('acme_credits_tx_log')) {
  function acme_credits_tx_log(array $args) {
    global $wpdb;

    $t = acme_table_credit_transactions();

    // Cache de colunas para não dar DESCRIBE toda hora
    static $colsCache = null;
    if ($colsCache === null) {
      $cols = $wpdb->get_results("DESCRIBE {$t}", ARRAY_A);
      $colsCache = [];
      foreach ((array)$cols as $c) {
        $colsCache[$c['Field']] = true;
      }
    }

    $now = current_time('mysql');

    // Defaults
    $row = [
      'user_id'            => (int)($args['user_id'] ?? 0),
      'service_id'         => (int)($args['service_id'] ?? 0),
      'service_slug'       => (string)($args['service_slug'] ?? ''),
      'service_name'       => (string)($args['service_name'] ?? ''),
      'type'               => (string)($args['type'] ?? 'debit'),     // debit|credit
      'credits'            => (int)($args['credits'] ?? 1),
      'status'             => (string)($args['status'] ?? 'success'), // success|failed
      'attempts'           => (int)($args['attempts'] ?? 1),
      'request_id'         => (string)($args['request_id'] ?? null),
      'actor_user_id'      => (int)($args['actor_user_id'] ?? 0),
      'notes'              => (string)($args['notes'] ?? ''),
      'meta'               => is_string($args['meta'] ?? null) ? ($args['meta'] ?? null) : (!empty($args['meta']) ? wp_json_encode($args['meta']) : null),
      'created_at'         => (string)($args['created_at'] ?? $now),

      // Campos de “carteira” (se você usa)
      'wallet_total_before'=> (int)($args['wallet_total_before'] ?? 0),
      'wallet_used_before' => (int)($args['wallet_used_before'] ?? 0),
      'wallet_total_after' => (int)($args['wallet_total_after'] ?? 0),
      'wallet_used_after'  => (int)($args['wallet_used_after'] ?? 0),
    ];

    // Remove colunas que não existem na tabela
    foreach (array_keys($row) as $k) {
      if (!isset($colsCache[$k])) unset($row[$k]);
    }

    $ok = $wpdb->insert($t, $row);
    if (!$ok) {
      error_log('ACME tx_log INSERT failed: ' . $wpdb->last_error);
      return false;
    }

    return (int)$wpdb->insert_id;
  }
}
