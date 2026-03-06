<?php
if (!defined('ABSPATH')) exit;

function acme_table_credit_contracts(): string {
  global $wpdb;
  return $wpdb->prefix . 'credit_contracts';
}

/**
 * Cria a tabela de contratos (assinaturas)
 * Um contrato por assinatura mensal.
 */
function acme_credit_contracts_activate() {
  global $wpdb;
  require_once ABSPATH . 'wp-admin/includes/upgrade.php';

  $t = acme_table_credit_contracts();
  $charset = $wpdb->get_charset_collate();

  $sql = "CREATE TABLE {$t} (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    child_user_id BIGINT UNSIGNED NOT NULL,
    service_id BIGINT UNSIGNED NOT NULL,
    credits_total INT NOT NULL DEFAULT 0,
    credits_used INT NOT NULL DEFAULT 0,
    valid_until DATETIME NOT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_child (child_user_id),
    KEY idx_service (service_id),
    KEY idx_valid (valid_until)
  ) {$charset};";

  dbDelta($sql);
}

/**
 * Cria um contrato de assinatura (novo contrato todo mês)
 */
function acme_contract_create(int $child_user_id, int $service_id, int $credits_total, string $valid_until) {
  if ($child_user_id <= 0 || $service_id <= 0) return new WP_Error('acme_invalid', 'Parâmetros inválidos.');
  if ($credits_total <= 0) return new WP_Error('acme_invalid', 'Quantidade inválida.');
  if (empty($valid_until)) return new WP_Error('acme_invalid', 'Vencimento é obrigatório.');

  $ts = strtotime($valid_until);
  if (!$ts) return new WP_Error('acme_invalid_date', 'Data de vencimento inválida.');

  global $wpdb;
  $t = acme_table_credit_contracts();
  $now = current_time('mysql');

  $ok = $wpdb->insert($t, [
    'child_user_id' => $child_user_id,
    'service_id'    => $service_id,
    'credits_total' => $credits_total,
    'credits_used'  => 0,
    'valid_until'   => date('Y-m-d H:i:s', $ts),
    'created_at'    => $now,
    'updated_at'    => $now,
  ]);

  if (!$ok) {
    return new WP_Error('acme_db', 'Erro ao criar contrato: ' . ($wpdb->last_error ?: 'db insert failed'));
  }

  return [
    'success'     => true,
    'contract_id' => (int) $wpdb->insert_id,
    'valid_until' => date('Y-m-d H:i:s', $ts),
  ];
}

/**
 * Status conforme regra do cliente (ATIVO / VENCIDO / EXCEDIDO)
 */
function acme_contract_status(string $valid_until, int $total, int $used): string {
  $now = current_time('timestamp');
  $exp = strtotime($valid_until);
  $avail = max(0, $total - $used);

  if ($exp && $now > $exp) return 'VENCIDO';
  if ($avail <= 0) return 'EXCEDIDO';
  return 'ATIVO';
}
