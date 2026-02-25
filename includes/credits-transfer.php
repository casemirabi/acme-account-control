<?php
if (!defined('ABSPATH')) exit;

/**
 * Transferência REAL de créditos:
 * Master -> Sub-Login (saldo próprio do Sub-Login)
 *
 * Regra:
 * - Debita do saldo disponível do Master
 * - Credita no saldo do Sub-Login
 * - Transação atômica (lock com FOR UPDATE)
 * - Sub-Login herda expires_at do Master (se existir)
 */

function acme_credits_transfer_child_to_grandchild(int $child_id, int $grandchild_id, string $service_slug, int $credits, ?string $notes = null, array $meta = [])
{
  if ($credits <= 0) return new WP_Error('acme_invalid_amount', 'Quantidade inválida.');
  if ($credits > 1000) return new WP_Error('acme_limit', 'Máximo por operação: 1000 créditos.');

  // valida vínculo: Sub-Login pertence ao Master
  if (!function_exists('acme_can_current_user_grant_to') || !acme_can_current_user_grant_to($grandchild_id)) {
    return new WP_Error('acme_forbidden', 'Você só pode conceder para seus próprios Sub-Logins.');
  }

  global $wpdb;

  $servicesT = acme_table_services();
  $walletT   = acme_table_wallet();

  // resolve service_id + nome (pra log)
  $service = $wpdb->get_row($wpdb->prepare(
    "SELECT id, slug, name FROM {$servicesT} WHERE slug=%s LIMIT 1",
    $service_slug
  ));

  if (!$service) return new WP_Error('acme_service', 'Serviço inválido.');

  $service_id = (int) $service->id;

  // abre transação
  $wpdb->query('START TRANSACTION');

  try {
    // lock wallet do Master
    $w_child = $wpdb->get_row($wpdb->prepare(
      "SELECT id, credits_total, credits_used, expires_at, status
       FROM {$walletT}
       WHERE master_user_id=%d AND service_id=%d
       FOR UPDATE",
      $child_id, $service_id
    ));

    if (!$w_child) {
      throw new Exception('Master não possui carteira para este serviço.');
    }

    // valida status/expiração do Master
    if (!empty($w_child->status) && $w_child->status !== 'active') {
      throw new Exception('Saldo do Master está inativo/expirado.');
    }
    if (!empty($w_child->expires_at)) {
      $now = current_time('timestamp');
      $exp = strtotime($w_child->expires_at);
      if ($exp && $exp < $now) throw new Exception('Saldo do Master está expirado.');
    }

    $child_total = (int) $w_child->credits_total;
    $child_used  = (int) $w_child->credits_used;
    $child_avail = max(0, $child_total - $child_used);

    if ($credits > $child_avail) {
      throw new Exception('Saldo insuficiente no Master para transferir.');
    }

    // lock/cria wallet do Sub-Login
    $w_grand = $wpdb->get_row($wpdb->prepare(
      "SELECT id, credits_total, credits_used, expires_at, status
       FROM {$walletT}
       WHERE master_user_id=%d AND service_id=%d
       FOR UPDATE",
      $grandchild_id, $service_id
    ));

    $now_mysql = current_time('mysql');
    $inherit_expires = !empty($w_child->expires_at) ? (string) $w_child->expires_at : null;

    if (!$w_grand) {
      $ok = $wpdb->insert($walletT, [
        'master_user_id' => $grandchild_id,
        'service_id'     => $service_id,
        'credits_total'  => 0,
        'credits_used'   => 0,
        'expires_at'     => $inherit_expires,
        'status'         => 'active',
        'created_at'     => $now_mysql,
        'updated_at'     => $now_mysql,
      ]);
      if (!$ok) throw new Exception('Erro ao criar wallet do Sub-Login: ' . ($wpdb->last_error ?: 'db insert failed'));

      $w_grand_id = (int) $wpdb->insert_id;
      $grand_total = 0;
      $grand_used  = 0;
    } else {
      $w_grand_id  = (int) $w_grand->id;
      $grand_total = (int) $w_grand->credits_total;
      $grand_used  = (int) $w_grand->credits_used;
    }

    // Debita do Master: reduz credits_total (não mexe em used)
    $new_child_total = $child_total - $credits;
    if ($new_child_total < $child_used) throw new Exception('Transferência deixaria credits_total < credits_used no Master.');

    $ok1 = $wpdb->update($walletT, [
      'credits_total' => $new_child_total,
      'updated_at'    => $now_mysql,
    ], ['id' => (int) $w_child->id]);

    if ($ok1 === false) throw new Exception('Erro ao debitar do Master: ' . ($wpdb->last_error ?: 'db update failed'));

    // Credita no Sub-Login: aumenta credits_total
    $new_grand_total = $grand_total + $credits;
    $ok2 = $wpdb->update($walletT, [
      'credits_total' => $new_grand_total,
      // herda expiração do Master (se tiver), senão mantém o que já tinha
      'expires_at'    => $inherit_expires ?: ($w_grand->expires_at ?? null),
      'updated_at'    => $now_mysql,
    ], ['id' => $w_grand_id]);

    if ($ok2 === false) throw new Exception('Erro ao creditar o Sub-Login: ' . ($wpdb->last_error ?: 'db update failed'));

    // Log em wp_credit_transactions:
    // 1) debit no Master
    if (function_exists('acme_credits_tx_log')) {
      acme_credits_tx_log([
        'user_id'            => $child_id,
        'actor_user_id'      => $child_id,
        'service_id'         => $service_id,
        'service_slug'       => $service->slug,
        'service_name'       => $service->name,
        'type'               => 'debit',
        'credits'            => $credits,
        'status'             => 'success',
        'attempts'           => 1,
        'notes'              => $notes ? ("Transferência para Sub-Login #{$grandchild_id} - " . $notes) : "Transferência para Sub-Login #{$grandchild_id}",
        'meta'               => wp_json_encode(array_merge($meta, ['transfer_to' => $grandchild_id])),
        'wallet_total_before'=> $child_total,
        'wallet_used_before' => $child_used,
        'wallet_total_after' => $new_child_total,
        'wallet_used_after'  => $child_used,
      ]);

      // 2) credit no Sub-Login (actor = Master)
      acme_credits_tx_log([
        'user_id'            => $grandchild_id,
        'actor_user_id'      => $child_id,
        'service_id'         => $service_id,
        'service_slug'       => $service->slug,
        'service_name'       => $service->name,
        'type'               => 'credit',
        'credits'            => $credits,
        'status'             => 'success',
        'attempts'           => 1,
        'notes'              => $notes ? ("Recebido do Master #{$child_id} - " . $notes) : "Recebido do Master #{$child_id}",
        'meta'               => wp_json_encode(array_merge($meta, ['transfer_from' => $child_id])),
        'wallet_total_before'=> $grand_total,
        'wallet_used_before' => $grand_used,
        'wallet_total_after' => $new_grand_total,
        'wallet_used_after'  => $grand_used,
      ]);
    }

    $wpdb->query('COMMIT');
    return ['success' => true];

  } catch (Exception $e) {
    $wpdb->query('ROLLBACK');
    return new WP_Error('acme_transfer_failed', $e->getMessage());
  }
}
