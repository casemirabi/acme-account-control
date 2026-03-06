<?php
if (!defined('ABSPATH'))
  exit;

/**
 * DEPENDE de helpers.php:
 * - acme_table_services()
 * - acme_table_wallet()
 * - acme_table_credit_transactions()
 */

if (!function_exists('acme_debug_db_error')) {
  function acme_debug_db_error(string $context): string
  {
    global $wpdb;
    $err = $wpdb->last_error ? $wpdb->last_error : 'sem last_error';
    $qry = $wpdb->last_query ? $wpdb->last_query : 'sem last_query';
    return $context . " | DB_ERROR: {$err} | LAST_QUERY: {$qry}";
  }
}

/** Resolve serviço por slug */
if (!function_exists('acme_service_get_by_slug')) {
  function acme_service_get_by_slug(string $slug)
  {
    global $wpdb;
    $servicesT = acme_table_services();
    return $wpdb->get_row($wpdb->prepare(
      "SELECT id, slug, name, credits_cost FROM {$servicesT} WHERE slug=%s LIMIT 1",
      $slug
    ));
  }
}

/** Lê wallet (ou null) */
if (!function_exists('acme_wallet_get')) {
  function acme_wallet_get(int $user_id, int $service_id)
  {
    global $wpdb;
    $walletT = acme_table_wallet();
    return $wpdb->get_row($wpdb->prepare(
      "SELECT * FROM {$walletT} WHERE master_user_id=%d AND service_id=%d LIMIT 1",
      $user_id,
      $service_id
    ));
  }
}

/**
 * LOG PURO: grava em wp_credit_transactions sem depender da wp_wallet.
 * Use isso para fluxos novos (contracts + lots).
 */
if (!function_exists('acme_credits_tx_log')) {
  function acme_credits_tx_log(array $data): array
  {
    global $wpdb;
    $txT = acme_table_credit_transactions();

    $now = current_time('mysql');

    // >>>>> AUTO-PREENCHER service_slug/service_name pelo service_id <<<<<
    if (
      (empty($data['service_slug']) || empty($data['service_name'])) &&
      !empty($data['service_id'])
    ) {
      $serviceId = (int) $data['service_id'];

      if ($serviceId > 0) {
        $servicesTable = acme_table_services(); // vem do helpers.php (carregado antes)

        $serviceRow = $wpdb->get_row(
          $wpdb->prepare("SELECT slug, name FROM {$servicesTable} WHERE id = %d LIMIT 1", $serviceId)
        );

        if ($serviceRow) {
          if (empty($data['service_slug'])) $data['service_slug'] = (string) $serviceRow->slug;
          if (empty($data['service_name'])) $data['service_name'] = (string) $serviceRow->name;
        }
      }
    }
    // >>>>> FIM <<<<<

    // defaults alinhados com sua tabela
    $row = array_merge([
      'user_id' => 0,              // alvo (quem recebeu ou quem foi debitado)
      'service_id' => 0,
      'service_slug' => null,
      'service_name' => null,
      'type' => null,           // 'credit' | 'debit'
      'credits' => 0,
      'status' => 'success',
      'attempts' => 1,
      'request_id' => null,
      'actor_user_id' => get_current_user_id(),
      'notes' => null,
      'meta' => null,           // json string

      // NOVO:
      'origin' => 'concession',
      'created_at' => $now,

      // wallet_* pode ficar NULL quando não usamos wallet
      'wallet_total_before' => 0,
      'wallet_used_before' => 0,
      'wallet_total_after' => 0,
      'wallet_used_after' => 0,
    ], $data);

    $row['user_id'] = (int) $row['user_id'];
    $row['service_id'] = (int) $row['service_id'];
    $row['credits'] = (int) $row['credits'];
    $row['attempts'] = (int) $row['attempts'];

    $row['wallet_total_before'] = (int) ($row['wallet_total_before'] ?? 0);
    $row['wallet_used_before'] = (int) ($row['wallet_used_before'] ?? 0);
    $row['wallet_total_after'] = (int) ($row['wallet_total_after'] ?? 0);
    $row['wallet_used_after'] = (int) ($row['wallet_used_after'] ?? 0);

    if ($row['user_id'] <= 0 || $row['service_id'] <= 0 || $row['credits'] <= 0 || empty($row['type'])) {
      return ['success' => false, 'message' => 'Dados inválidos para registrar transação.', 'tx_id' => null];
    }

    // normaliza meta
    if (is_array($row['meta'])) {
      $row['meta'] = wp_json_encode($row['meta']);
    }

    $ok = $wpdb->insert($txT, $row);

    if ($ok === false) {
      return ['success' => false, 'message' => acme_debug_db_error('Erro ao inserir transação (log puro)'), 'tx_id' => null];
    }

    return ['success' => true, 'message' => 'Transação registrada.', 'tx_id' => (int) $wpdb->insert_id];
  }
}


/**
 * Concede créditos e grava transação (tipo=grant)
 * $service pode ser slug (ex: 'clt/extrato') ou ID numérico
 * Retorna SEMPRE array:
 *   ['success'=>bool,'message'=>string,'tx_id'=>int|null]
 */
if (!function_exists('acme_credits_grant')) {
  function acme_credits_grant(
    int $user_id,
    $service,
    int $credits_amount,
    ?string $expires_at = null,
    ?string $notes = null,
    ?array $meta = null
  ): array {

    if ($user_id <= 0 || $credits_amount <= 0) {
      return ['success' => false, 'message' => 'Parâmetros inválidos.', 'tx_id' => null];
    }

    global $wpdb;
    $walletT = acme_table_wallet();
    $txT = acme_table_credit_transactions();

    // 1) Resolver service_id
    $service_id = 0;
    $service_slug = null;

    if (is_numeric($service)) {
      $service_id = (int) $service;
    } else {
      $service_slug = sanitize_text_field((string) $service);
      if ($service_slug === '') {
        return ['success' => false, 'message' => 'Serviço inválido.', 'tx_id' => null];
      }
      $svc = acme_service_get_by_slug($service_slug);
      if (!$svc) {
        return ['success' => false, 'message' => 'Serviço não encontrado.', 'tx_id' => null];
      }
      $service_id = (int) $svc->id;
    }

    $actor_id = get_current_user_id();
    $now = current_time('mysql');

    // Normaliza meta (JSON)
    $meta = is_array($meta) ? $meta : [];
    $meta_json = wp_json_encode($meta);

    // 2) Start transaction
    $wpdb->query('START TRANSACTION');

    // 3) Wallet BEFORE
    $before = acme_wallet_get($user_id, $service_id);
    if ($wpdb->last_error) {
      $wpdb->query('ROLLBACK');
      return ['success' => false, 'message' => acme_debug_db_error('Erro ao buscar wallet'), 'tx_id' => null];
    }

    $before_total = (int) ($before->credits_total ?? 0);
    $before_used = (int) ($before->credits_used ?? 0);

    // 4) Atualiza/Cria wallet
    if (!$before) {
      $ok = $wpdb->insert($walletT, [
        'master_user_id' => $user_id,
        'service_id' => $service_id,
        'credits_total' => $credits_amount,
        'credits_used' => 0,
        'expires_at' => $expires_at ?: null,
        'status' => 'active',
        'created_at' => $now,
        'updated_at' => $now,
      ]);

      if ($ok === false) {
        $wpdb->query('ROLLBACK');
        return ['success' => false, 'message' => acme_debug_db_error('Erro ao inserir wallet'), 'tx_id' => null];
      }
    } else {
      $new_total = $before_total + $credits_amount;

      $upd = [
        'credits_total' => $new_total,
        'updated_at' => $now,
        'status' => 'active',
      ];
      if ($expires_at !== null && $expires_at !== '') {
        $upd['expires_at'] = $expires_at;
      }

      $ok = $wpdb->update($walletT, $upd, ['id' => (int) $before->id]);

      if ($ok === false) {
        $wpdb->query('ROLLBACK');
        return ['success' => false, 'message' => acme_debug_db_error('Erro ao atualizar wallet'), 'tx_id' => null];
      }
    }

    // 5) Wallet AFTER
    $after = acme_wallet_get($user_id, $service_id);
    if ($wpdb->last_error || !$after) {
      $wpdb->query('ROLLBACK');
      return ['success' => false, 'message' => acme_debug_db_error('Erro ao buscar wallet (after)'), 'tx_id' => null];
    }

    $after_total = (int) $after->credits_total;
    $after_used = (int) $after->credits_used;

    // 6) INSERT transação
    // Campos que estou assumindo (ajuste se sua tabela tiver nomes diferentes):
    // type, actor_user_id, target_user_id, service_id,
    // amount, wallet_total_before, wallet_used_before, wallet_total_after, wallet_used_after,
    // status, attempts, notes, meta_json, created_at
    $insert = $wpdb->insert($txT, [
      'type' => 'grant',
      'actor_user_id' => $actor_id,
      'user_id' => $user_id,
      'service_id' => $service_id,
      'credits' => $credits_amount,
      'attempts' => 1,
      'wallet_total_before' => $before_total,
      'wallet_used_before' => $before_used,
      'wallet_total_after' => $after_total,
      'wallet_used_after' => $after_used,
      'status' => 'success',
      'notes' => $notes,
      'meta' => $meta_json,

      // NOVO:
      'origin' => 'concession',

      'created_at' => $now,
    ]);

    if ($insert === false) {
      $wpdb->query('ROLLBACK');
      return ['success' => false, 'message' => acme_debug_db_error('Erro ao inserir transação'), 'tx_id' => null];
    }

    $tx_id = (int) $wpdb->insert_id;

    $wpdb->query('COMMIT');

    return ['success' => true, 'message' => 'Créditos concedidos.', 'tx_id' => $tx_id];
  }
}
