<?php
if (!defined('ABSPATH'))
  exit;

/**
 * Handler único (serve tanto wp-admin quanto front)
 */
if (!function_exists('acme_handle_admin_grant_credits')) {
  add_action('admin_post_acme_admin_grant_credits', 'acme_handle_admin_grant_credits');

  function acme_handle_admin_grant_credits()
  {
    if (!is_user_logged_in())
      wp_die('Você precisa estar logado.');

    $me = wp_get_current_user();
    $is_admin = current_user_can('manage_options');
    $is_child = function_exists('acme_user_has_role')
      ? acme_user_has_role($me, 'child')
      : in_array('child', (array) $me->roles, true);

    if (!$is_admin && !$is_child)
      wp_die('Sem permissão.');

    if (!isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], 'acme_admin_grant_credits')) {
      wp_die('Nonce inválido.');
    }

    $user_id = isset($_POST['user_id']) ? (int) $_POST['user_id'] : 0;
    $service_slug = isset($_POST['service_slug']) ? sanitize_key($_POST['service_slug']) : '';
    $credits = isset($_POST['credits']) ? (int) $_POST['credits'] : 0;
    $grant_type = isset($_POST['grant_type']) ? sanitize_key($_POST['grant_type']) : 'full';

    $expires_at = null;
    if (!empty($_POST['expires_at'])) {
      $expires_at = sanitize_text_field($_POST['expires_at']); // YYYY-MM-DD
    }

    $notes = !empty($_POST['notes']) ? sanitize_text_field($_POST['notes']) : null;

    if ($user_id <= 0 || $service_slug === '' || $credits <= 0) {
      wp_die('Parâmetros inválidos.');
    }

    // ===== slug -> service_id =====
    global $wpdb;
    $servicesT = acme_table_services();

    $service_id = (int) $wpdb->get_var($wpdb->prepare(
      "SELECT id FROM {$servicesT} WHERE slug=%s LIMIT 1",
      $service_slug
    ));

    if ($service_id <= 0) {
      wp_die('Serviço inválido (slug não encontrado).');
    }

    $meta = [
      'source' => is_admin() ? 'wp-admin' : 'front',
      'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
    ];

    // ===== EXECUÇÃO =====
    $res = null;

    if ($is_admin) {

      if ($grant_type === 'subscription') {

        // Assinatura: só Masters (child) e vencimento obrigatório
        if (empty($expires_at)) {
          $res = new WP_Error('acme_missing_exp', 'Vencimento é obrigatório para assinatura.');
        } else {
          $u = get_user_by('id', $user_id);
          $is_target_child = $u && in_array('child', (array) $u->roles, true);

          if (!$is_target_child) {
            $res = new WP_Error('acme_target', 'Assinatura só pode ser concedida para Masters.');
          } else {
            // cria contrato + lote subscription
            $c = acme_contract_create($user_id, $service_id, $credits, $expires_at . ' 23:59:59');

            if (is_wp_error($c)) {
              $res = $c;
            } else {
              $lot = acme_lot_create(
                $user_id,
                $service_id,
                'subscription',
                $credits,
                $c['valid_until'],
                (int) $c['contract_id'],
                $meta
              );

              $res = $lot;

              if (!is_wp_error($res) && function_exists('acme_credits_tx_log')) {
                acme_credits_tx_log([
                  'type' => 'credit',
                  'user_id' => $user_id,
                  'actor_user_id' => get_current_user_id(),
                  'service_id' => $service_id,
                  'service_slug' => $service_slug,
                  'credits' => $credits,
                  'status' => 'success',
                  'notes' => $notes ?: 'Assinatura concedida',
                  'meta' => [
                    'grant_type' => 'subscription',
                    'contract_id' => (int) $c['contract_id'],
                    'valid_until' => (string) $c['valid_until'],
                  ],
                ]);
              }

            }
          }
        }

      } else {

        // Full: sem vencimento
        $lot = acme_lot_create($user_id, $service_id, 'full', $credits, null, null, $meta);


        $res = $lot;

        if (!is_wp_error($res) && function_exists('acme_credits_tx_log')) {
          acme_credits_tx_log([
            'type' => 'credit',
            'user_id' => $user_id,
            'actor_user_id' => get_current_user_id(),
            'service_id' => $service_id,
            'service_slug' => $service_slug,
            'credits' => $credits,
            'status' => 'success',
            'notes' => $notes ?: 'Crédito FULL concedido',
            'meta' => [
              'grant_type' => 'full',
            ],
          ]);
        }




      }

    } else {

      // Master: distribuição para Sub-Login preservando validade
      $transfer = acme_lots_transfer_child_to_grandchild(
        (int) $me->ID,
        (int) $user_id,
        (int) $service_id,
        (int) $credits,
        $notes,
        $meta
      );



      $res = $transfer;
    }

    // ===== redirect =====
    $back = wp_get_referer() ?: admin_url('admin.php?page=acme_credits');
    $back = remove_query_arg(['acme_msg', 'acme_err'], $back);

    $ok = false;
    $err_msg = null;

    if (is_wp_error($res)) {
      $ok = false;
      $err_msg = $res->get_error_message();
    } elseif (is_array($res) && array_key_exists('success', $res)) {
      $ok = (bool) $res['success'];
      if (!$ok && !empty($res['message']))
        $err_msg = (string) $res['message'];
    } else {
      $ok = ($res === true);
    }

    $back = add_query_arg('acme_msg', $ok ? 'ok' : 'err', $back);
    if (!$ok && $err_msg) {
      $back = add_query_arg('acme_err', rawurlencode($err_msg), $back);
    }

    wp_safe_redirect($back);
    exit;
  }
}

