<?php
if (!defined('ABSPATH')) exit;

function acme_table_credit_lots(): string
{
  global $wpdb;
  return $wpdb->prefix . 'credit_lots';
}

/**
 * Lotes de crédito:
 * - subscription: tem expires_at e contract_id
 * - full: expires_at NULL
 * - transfer: criado quando Filho distribui para Neto (preserva expires_at do lote de origem)
 */
function acme_credit_lots_activate()
{
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

function acme_lots_available_sum(int $user_id, int $service_id): int
{
  global $wpdb;
  $t = acme_table_credit_lots();
  $now = current_time('mysql');

  $sum = (int) $wpdb->get_var($wpdb->prepare(
    "SELECT COALESCE(SUM(GREATEST(credits_total - credits_used, 0)), 0)
     FROM {$t}
     WHERE owner_user_id=%d AND service_id=%d
       AND (expires_at IS NULL OR expires_at >= %s)",
    $user_id,
    $service_id,
    $now
  ));

  return max(0, $sum);
}

function acme_lot_create(int $owner_user_id, int $service_id, string $source, int $credits_total, ?string $expires_at, ?int $contract_id = null, array $meta = [])
{
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
function acme_lots_transfer_child_to_grandchild(int $child_id, int $grandchild_id, int $service_id, int $credits, ?string $notes = null, array $meta = [])
{
  if ($credits <= 0) return new WP_Error('acme_invalid_amount', 'Quantidade inválida.');
  if ($credits > 1000) return new WP_Error('acme_limit', 'Máximo por operação: 1000 créditos.');

  // valida vínculo (precisa do módulo users/distribution)
  if (!function_exists('acme_can_current_user_grant_to') || !acme_can_current_user_grant_to($grandchild_id)) {
    return new WP_Error('acme_forbidden', 'Você só pode distribuir para seus próprios Netos.');
  }


    // ===== VALIDA STATUS DO USUÁRIO DESTINO =====
  global $wpdb;
  $statusT = acme_table_status();

  $target_status = $wpdb->get_var($wpdb->prepare(
    "SELECT status
     FROM {$statusT}
     WHERE user_id = %d
     LIMIT 1",
    $grandchild_id
  ));

  $target_status = $target_status ? (string) $target_status : 'active';

  if ($target_status !== 'active') {
    return new WP_Error('acme_target_inactive', 'Não é possível distribuir créditos para usuário inativo.');
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
      $child_id,
      $service_id,
      $now
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
          'origin_source' => (string)$r->source,
          'origin_contract_id' => $r->contract_id ? (int)$r->contract_id : null,
        ])
      );
      if (is_wp_error($resLot)) throw new Exception($resLot->get_error_message());

      $remaining -= $take;
    }

    // Log opcional em wp_credit_transactions, se existir logger
    if (function_exists('acme_credits_tx_log')) {
      //$notes_out = $notes ? ("Distribuição para Sub-Login #{$grandchild_id} - {$notes}") : "Distribuição para Sub-Login #{$grandchild_id}";
      $grandchild_user = get_userdata((int) $grandchild_id);
      $grandchild_name = $grandchild_user ? $grandchild_user->display_name : ("#{$grandchild_id}");

      $notes_suffix = $notes ? ' - ' . $notes : '';
      $notes_out = "Distribuição para {$grandchild_name}{$notes_suffix}";

      acme_credits_tx_log([
        'user_id'       => $child_id,
        'actor_user_id' => $child_id,
        'service_id'    => $service_id,
        'type'          => 'debit',
        'credits'       => $credits,
        'origin'       => 'concession',
        'status'        => 'success',
        'attempts'      => 1,
        'notes'         => $notes_out,
        'meta'          => wp_json_encode(array_merge($meta, ['transfer_to' => $grandchild_id])),
        'created_at'    => $now,
      ]);

      //$notes_in = $notes ? ("Recebido do Master #{$child_id} - {$notes}") : "Recebido do Master #{$child_id}";
      $child_user = get_userdata((int) $child_id);

      if ($child_user) {
        //$child_label = "{$child_user->display_name} (#{$child_id})";
        $child_label = "{$child_user->display_name}";
      } else {
        $child_label = "#{$child_id}";
      }

      $notes_suffix = $notes ? ' - ' . $notes : '';
      $notes_in = "Recebido de {$child_label}{$notes_suffix}";

      acme_credits_tx_log([
        'user_id'       => $grandchild_id,
        'actor_user_id' => $child_id,
        'service_id'    => $service_id,
        'type'          => 'credit',
        'credits'       => $credits,
        'origin'       => 'concession',
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


/**
 * Recuperação (estorno) de créditos:
 *
 * 1) Master -> recupera de Sub-Login (grandchild)
 *    - Debita créditos disponíveis do Sub-Login (credits_used nos lotes dele)
 *    - "Devolve" para o Master reduzindo credits_used nos lotes de origem (origin_lot)
 *    - Transação atômica com lock FOR UPDATE
 *
 * 2) Admin -> recupera de Master (child)
 *    - Apenas debita do Master (aumenta credits_used nos lotes dele)
 *    - Não credita para "Admin" (não existe carteira do Admin no modelo de lotes)
 *
 * Observação:
 * - Recupera somente créditos AINDA não usados (saldo disponível).
 * - Para recuperar créditos já consumidos, é necessário um processo de estorno de consumo (fora do escopo).
 */

function acme_lots_recover_grandchild_to_child(int $child_id, int $grandchild_id, int $service_id, int $credits, ?string $notes = null, array $meta = [])
{
  if ($credits <= 0) return new WP_Error('acme_invalid_amount', 'Quantidade inválida.');
  if ($credits > 1000) return new WP_Error('acme_limit', 'Máximo por operação: 1000 créditos.');

  // valida vínculo (precisa do módulo users/distribution)
  if (!function_exists('acme_can_current_user_grant_to') || !acme_can_current_user_grant_to($grandchild_id)) {
    return new WP_Error('acme_forbidden', 'Você só pode recuperar de seus próprios Sub-Logins.');
  }

  global $wpdb;
  $lotsT = acme_table_credit_lots();
  $now = current_time('mysql');

  $wpdb->query('START TRANSACTION');

  try {
    // Lock nos lotes do Sub-Login (somente ativos e com saldo)
    $grand_lots = $wpdb->get_results($wpdb->prepare(
      "SELECT id, credits_total, credits_used, expires_at, meta
       FROM {$lotsT}
       WHERE owner_user_id=%d AND service_id=%d
         AND (expires_at IS NULL OR expires_at >= %s)
         AND (credits_total - credits_used) > 0
       ORDER BY (expires_at IS NULL) ASC, expires_at ASC, id ASC
       FOR UPDATE",
      $grandchild_id,
      $service_id,
      $now
    ));

    $available = 0;
    foreach ($grand_lots as $r) $available += max(0, ((int)$r->credits_total - (int)$r->credits_used));
    if ($credits > $available) throw new Exception('Saldo insuficiente no Sub-Login para recuperar.');

    $remaining = $credits;

    foreach ($grand_lots as $r) {
      if ($remaining <= 0) break;

      $lot_avail = max(0, ((int)$r->credits_total - (int)$r->credits_used));
      if ($lot_avail <= 0) continue;

      $take = min($lot_avail, $remaining);

      // Meta precisa conter origin_lot para devolver corretamente
      $metaArr = [];
      if (!empty($r->meta)) {
        $decoded = json_decode((string)$r->meta, true);
        if (is_array($decoded)) $metaArr = $decoded;
      }

      $origin_lot_id = isset($metaArr['origin_lot']) ? (int) $metaArr['origin_lot'] : 0;
      if ($origin_lot_id <= 0) {
        throw new Exception('Lote do Sub-Login sem origin_lot (não é possível devolver ao Master com segurança).');
      }

      // Lock do lote de origem no Master
      $origin = $wpdb->get_row($wpdb->prepare(
        "SELECT id, owner_user_id, service_id, credits_total, credits_used, expires_at
         FROM {$lotsT}
         WHERE id=%d
         FOR UPDATE",
        $origin_lot_id
      ));

      if (!$origin || (int)$origin->owner_user_id !== $child_id || (int)$origin->service_id !== $service_id) {
        throw new Exception('origin_lot inválido (não pertence ao Master ou service_id não confere).');
      }

      $origin_used = (int) $origin->credits_used;
      if ($take > $origin_used) {
        // Não permite "desfazer" mais do que foi transferido daquele lote
        $take = $origin_used;
        if ($take <= 0) continue;
      }

      // (A) Debita no Sub-Login (marca como usado)
      $new_grand_used = (int)$r->credits_used + $take;
      $ok1 = $wpdb->update($lotsT, [
        'credits_used' => $new_grand_used,
        'updated_at'   => $now,
      ], ['id' => (int)$r->id]);

      if ($ok1 === false) throw new Exception('Erro ao debitar lote do Sub-Login: ' . ($wpdb->last_error ?: 'db update failed'));

      // (B) Devolve no Master (reduz used no lote origem)
      $new_origin_used = $origin_used - $take;
      $ok2 = $wpdb->update($lotsT, [
        'credits_used' => $new_origin_used,
        'updated_at'   => $now,
      ], ['id' => (int)$origin->id]);

      if ($ok2 === false) throw new Exception('Erro ao devolver crédito ao Master: ' . ($wpdb->last_error ?: 'db update failed'));

      $remaining -= $take;
    }

    if ($remaining > 0) {
      // Em teoria não deveria acontecer por conta do available, mas garante consistência
      throw new Exception('Não foi possível recuperar o total solicitado (inconsistência de origem).');
    }

    // Log opcional
    if (function_exists('acme_credits_tx_log')) {
      //$notes_out = $notes ? ("Recuperação para Master #{$child_id} - {$notes}") : "Recuperação para Master #{$child_id}";
      $usuario_master = get_userdata((int) $child_id);
      $nome_master = $usuario_master ? $usuario_master->display_name : 'Usuário removido';

      $notes_suffix = $notes ? ' - ' . $notes : '';
      $notes_out = "Recuperação para {$nome_master}{$notes_suffix}";

      acme_credits_tx_log([
        'user_id'       => $grandchild_id,
        'actor_user_id' => $child_id,
        'service_id'    => $service_id,
        'type'          => 'debit',
        'credits'       => $credits,
        'origin'       => 'reserved',
        'status'        => 'success',
        'attempts'      => 1,
        'notes'         => $notes_out,
        'meta'          => wp_json_encode(array_merge($meta, ['recover_to' => $child_id])),
        'created_at'    => $now,
      ]);

      //$notes_in = $notes ? ("Recuperado do Sub-Login #{$grandchild_id} - {$notes}") : "Recuperado do Sub-Login #{$grandchild_id}";
      $grandchild_user = get_userdata((int) $grandchild_id);
      $grandchild_name = $grandchild_user ? $grandchild_user->display_name : ("#{$grandchild_id}");

      $notes_suffix = $notes ? ' - ' . $notes : '';
      $notes_in = "Recuperado de {$grandchild_name}{$notes_suffix}";


      acme_credits_tx_log([
        'user_id'       => $child_id,
        'actor_user_id' => $child_id,
        'service_id'    => $service_id,
        'type'          => 'credit',
        'credits'       => $credits,
        'origin'       => 'reserved',
        'status'        => 'success',
        'attempts'      => 1,
        'notes'         => $notes_in,
        'meta'          => wp_json_encode(array_merge($meta, ['recover_from' => $grandchild_id])),
        'created_at'    => $now,
      ]);
    }

    $wpdb->query('COMMIT');
    return ['success' => true];
  } catch (Exception $e) {
    $wpdb->query('ROLLBACK');
    return new WP_Error('acme_recover_failed', $e->getMessage());
  }
}

function acme_lots_admin_recover_from_child(int $child_id, int $service_id, int $credits, ?string $notes = null, array $meta = [])
{
  if ($credits <= 0) return new WP_Error('acme_invalid_amount', 'Quantidade inválida.');
  if ($credits > 5000) return new WP_Error('acme_limit', 'Máximo por operação: 5000 créditos.');

  global $wpdb;
  $lotsT = acme_table_credit_lots();
  $now = current_time('mysql');

  $wpdb->query('START TRANSACTION');

  try {
    // Lock nos lotes do Master, mesma regra de consumo (expira primeiro)
    $rows = $wpdb->get_results($wpdb->prepare(
      "SELECT id, credits_total, credits_used, expires_at, source, contract_id
       FROM {$lotsT}
       WHERE owner_user_id=%d AND service_id=%d
         AND (expires_at IS NULL OR expires_at >= %s)
         AND (credits_total - credits_used) > 0
       ORDER BY (expires_at IS NULL) ASC, expires_at ASC, id ASC
       FOR UPDATE",
      $child_id,
      $service_id,
      $now
    ));

    $available = 0;
    foreach ($rows as $r) $available += max(0, ((int)$r->credits_total - (int)$r->credits_used));
    if ($credits > $available) throw new Exception('Saldo insuficiente no Master para recuperar.');

    $remaining = $credits;

    foreach ($rows as $r) {
      if ($remaining <= 0) break;

      $lot_avail = max(0, ((int)$r->credits_total - (int)$r->credits_used));
      if ($lot_avail <= 0) continue;

      $take = min($lot_avail, $remaining);
      $new_used = (int)$r->credits_used + $take;

      $ok = $wpdb->update($lotsT, [
        'credits_used' => $new_used,
        'updated_at'   => $now,
      ], ['id' => (int)$r->id]);

      if ($ok === false) throw new Exception('Erro ao debitar lote do Master: ' . ($wpdb->last_error ?: 'db update failed'));

      $remaining -= $take;
    }

    if ($remaining > 0) throw new Exception('Não foi possível recuperar o total solicitado.');

    // Log opcional (apenas debit no Master)
    if (function_exists('acme_credits_tx_log')) {
      $notes_out = $notes ? ("Recuperação pelo Admin - {$notes}") : "Recuperação pelo Admin";
      acme_credits_tx_log([
        'user_id'       => $child_id,
        'actor_user_id' => get_current_user_id(),
        'service_id'    => $service_id,
        'type'          => 'debit',
        'credits'       => $credits,
        'origin'       => 'reserved',

        'status'        => 'success',
        'attempts'      => 1,
        'notes'         => $notes_out,
        'meta'          => wp_json_encode(array_merge($meta, ['recover_by_admin' => true])),
        'created_at'    => $now,
      ]);
    }

    $wpdb->query('COMMIT');
    return ['success' => true];
  } catch (Exception $e) {
    $wpdb->query('ROLLBACK');
    return new WP_Error('acme_recover_failed', $e->getMessage());
  }
}







if (!defined('ABSPATH')) exit;

if (!function_exists('acme_table_credit_transactions')) {
  function acme_table_credit_transactions(): string
  {
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
  function acme_credits_tx_log(array $args)
  {
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
      'wallet_total_before' => (int)($args['wallet_total_before'] ?? 0),
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
