<?php
if (!defined('ABSPATH')) exit;

/**
 * Distribuição Filho -> Neto (limite de uso), sem saldo próprio do Neto.
 * Consumo real (debit) ocorrerá SEMPRE no saldo (wallet) do Filho.
 */

function acme_table_credit_allowances(): string {
  global $wpdb;
  return $wpdb->prefix . 'credit_allowances';
}

/** Cria tabela de limites */
function acme_credit_allowances_activate() {
  global $wpdb;
  require_once ABSPATH . 'wp-admin/includes/upgrade.php';

  $t = acme_table_credit_allowances();
  $charset = $wpdb->get_charset_collate();

  $sql = "CREATE TABLE {$t} (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    parent_user_id BIGINT UNSIGNED NOT NULL,
    child_user_id  BIGINT UNSIGNED NOT NULL,
    service_id BIGINT UNSIGNED NOT NULL,
    limit_total INT NOT NULL DEFAULT 0,
    limit_used  INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_parent_child_service (parent_user_id, child_user_id, service_id),
    KEY idx_parent (parent_user_id),
    KEY idx_child (child_user_id),
    KEY idx_service (service_id)
  ) {$charset};";

  dbDelta($sql);
}

/** Retorna o Filho (parent) de um Neto via tabela account_links (depth=2) */
function acme_get_parent_child_of_grandchild(int $grandchild_id): int {
  global $wpdb;
  $linksT = acme_table_links();
  $parent = (int) $wpdb->get_var($wpdb->prepare(
    "SELECT parent_user_id FROM {$linksT} WHERE child_user_id=%d AND depth=2 LIMIT 1",
    $grandchild_id
  ));
  return $parent;
}


/** Saldo disponível do Filho (wallet) para um service_id */
function acme_child_wallet_available(int $child_id, int $service_id): int {
  global $wpdb;
  $walletT = acme_table_wallet();

  $row = $wpdb->get_row($wpdb->prepare(
    "SELECT credits_total, credits_used, expires_at, status
     FROM {$walletT}
     WHERE master_user_id=%d AND service_id=%d
     LIMIT 1",
    $child_id, $service_id
  ));

  if (!$row) return 0;

  // status/expiração: se inativo ou expirado, zera disponível
  if (!empty($row->status) && $row->status !== 'active') return 0;

  if (!empty($row->expires_at)) {
    $now = current_time('timestamp');
    $exp = strtotime($row->expires_at);
    if ($exp && $exp < $now) return 0;
  }

  $total = (int) $row->credits_total;
  $used  = (int) $row->credits_used;
  $avail = $total - $used;

  return max(0, $avail);
}

/** Soma limites REMANESCENTES já alocados para netos (exceto um neto opcional) */
function acme_sum_allocated_remaining(int $parent_id, int $service_id, int $exclude_grandchild_id = 0): int {
  global $wpdb;
  $t = acme_table_credit_allowances();

  if ($exclude_grandchild_id > 0) {
    $sql = "SELECT COALESCE(SUM(GREATEST(limit_total - limit_used, 0)),0)
            FROM {$t}
            WHERE parent_user_id=%d AND service_id=%d AND child_user_id<>%d";
    return (int) $wpdb->get_var($wpdb->prepare($sql, $parent_id, $service_id, $exclude_grandchild_id));
  }

  $sql = "SELECT COALESCE(SUM(GREATEST(limit_total - limit_used, 0)),0)
          FROM {$t}
          WHERE parent_user_id=%d AND service_id=%d";
  return (int) $wpdb->get_var($wpdb->prepare($sql, $parent_id, $service_id));
}

/** Define/atualiza o limite do Neto (sem mexer no saldo do Filho) */
function acme_allowance_set_limit(int $parent_id, int $grandchild_id, int $service_id, int $new_limit_total) {
  if ($new_limit_total < 0) return new WP_Error('acme_invalid_limit', 'Limite inválido.');

  global $wpdb;
  $t = acme_table_credit_allowances();

  // valida vínculo: Neto realmente pertence ao Filho
  $real_parent = acme_get_parent_child_of_grandchild($grandchild_id);
  if ($real_parent !== $parent_id) {
    return new WP_Error('acme_forbidden', 'Você só pode conceder para seus próprios netos.');
  }

  // pega registro atual (para não reduzir abaixo do usado)
  $cur = $wpdb->get_row($wpdb->prepare(
    "SELECT id, limit_used, limit_total FROM {$t} WHERE parent_user_id=%d AND child_user_id=%d AND service_id=%d LIMIT 1",
    $parent_id, $grandchild_id, $service_id
  ));
  $limit_used = $cur ? (int) $cur->limit_used : 0;

  if ($new_limit_total < $limit_used) {
    return new WP_Error('acme_limit_too_low', 'O novo limite não pode ser menor que o já utilizado.');
  }

  // anti “overcommit”: soma limites remanescentes + novo remanescente não pode passar do disponível do Filho
  $available = acme_child_wallet_available($parent_id, $service_id);
  $others_remaining = acme_sum_allocated_remaining($parent_id, $service_id, $grandchild_id);
  $this_remaining = max(0, $new_limit_total - $limit_used);

  if (($others_remaining + $this_remaining) > $available) {
    return new WP_Error(
      'acme_overcommit',
      'Limite excede o saldo disponível do Master (considerando os limites já distribuídos).'
    );
  }

  $now = current_time('mysql');

  if ($cur) {
    $ok = $wpdb->update($t, [
      'limit_total' => $new_limit_total,
      'updated_at'  => $now,
    ], ['id' => (int) $cur->id]);

    return ($ok !== false) ? ['success' => true] : new WP_Error('acme_db', 'Erro ao atualizar limite.');
  }

  $ok = $wpdb->insert($t, [
    'parent_user_id' => $parent_id,
    'child_user_id'  => $grandchild_id,
    'service_id'     => $service_id,
    'limit_total'    => $new_limit_total,
    'limit_used'     => 0,
    'created_at'     => $now,
    'updated_at'     => $now,
  ]);

  return ($ok) ? ['success' => true] : new WP_Error('acme_db', 'Erro ao criar limite.');
}

/** Saldo efetivo do Neto = min( limite restante, saldo disponível do Filho ) */
function acme_grandchild_effective_available(int $grandchild_id, int $service_id): int {
  global $wpdb;
  $t = acme_table_credit_allowances();

  $parent_id = acme_get_parent_child_of_grandchild($grandchild_id);
  if ($parent_id <= 0) return 0;

  $allow = $wpdb->get_row($wpdb->prepare(
    "SELECT limit_total, limit_used FROM {$t}
     WHERE parent_user_id=%d AND child_user_id=%d AND service_id=%d LIMIT 1",
    $parent_id, $grandchild_id, $service_id
  ));

  $limit_remaining = $allow ? max(0, ((int)$allow->limit_total - (int)$allow->limit_used)) : 0;
  $parent_avail = acme_child_wallet_available($parent_id, $service_id);

  return min($limit_remaining, $parent_avail);
}

/** Permissão: Admin total, Filho só para netos dele */
function acme_can_current_user_grant_to(int $target_user_id): bool {
  if (!is_user_logged_in()) return false;

  $me = wp_get_current_user();
  if (current_user_can('manage_options')) return true;

  if (acme_user_has_role($me, 'child')) {
    $parent = acme_get_parent_child_of_grandchild($target_user_id);
    return $parent === (int) $me->ID;
  }

  return false;
}
