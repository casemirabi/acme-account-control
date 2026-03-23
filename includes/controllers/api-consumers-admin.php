<?php
if (!defined('ABSPATH')) exit;

add_action('admin_menu', function () {
  if (!current_user_can('manage_options')) {
    return;
  }

  global $admin_page_hooks, $menu;

  $acmeRootExists = false;

  if (is_array($admin_page_hooks) && isset($admin_page_hooks['acme_root'])) {
    $acmeRootExists = true;
  }

  if (!$acmeRootExists && is_array($menu)) {
    foreach ($menu as $menuItem) {
      if (!empty($menuItem[2]) && $menuItem[2] === 'acme_root') {
        $acmeRootExists = true;
        break;
      }
    }
  }

  if (!$acmeRootExists) {
    add_menu_page(
      'ACME',
      'ACME',
      'manage_options',
      'acme_root',
      'acme_api_consumers_menu_fallback_page',
      'dashicons-shield',
      58
    );
  }

  add_submenu_page(
    'acme_root',
    'Chaves da API',
    'Chaves da API',
    'manage_options',
    'acme-api-consumers',
    'acme_api_consumers_admin_page'
  );
}, 99);

if (!function_exists('acme_api_consumers_menu_fallback_page')) {
  function acme_api_consumers_menu_fallback_page(): void
  {
    if (!current_user_can('manage_options')) {
      wp_die('Sem permissão.');
    }

    wp_safe_redirect(admin_url('admin.php?page=acme-api-consumers'));
    exit;
  }
}

add_action('admin_post_acme_api_consumer_create', function () {
  if (!current_user_can('manage_options')) {
    wp_die('Sem permissão.');
  }

  check_admin_referer('acme_api_consumer_create');

  $wpUserId = isset($_POST['wp_user_id']) ? (int) $_POST['wp_user_id'] : 0;
  $consumerName = isset($_POST['consumer_name']) ? sanitize_text_field(wp_unslash($_POST['consumer_name'])) : '';
  $allowedServices = isset($_POST['allowed_services']) ? (array) wp_unslash($_POST['allowed_services']) : ['clt'];

  $createResult = acme_api_consumer_create($wpUserId, $consumerName, $allowedServices);

  if (is_wp_error($createResult)) {
    acme_api_consumer_store_admin_notice('error', $createResult->get_error_message());
    wp_safe_redirect(admin_url('admin.php?page=acme-api-consumers'));
    exit;
  }

  set_transient('acme_api_consumer_plain_key_' . get_current_user_id(), $createResult, 300);
  acme_api_consumer_store_admin_notice('success', 'Chave criada com sucesso.');
  wp_safe_redirect(admin_url('admin.php?page=acme-api-consumers'));
  exit;
});

add_action('admin_post_acme_api_consumer_regenerate', function () {
  if (!current_user_can('manage_options')) {
    wp_die('Sem permissão.');
  }

  check_admin_referer('acme_api_consumer_regenerate');

  $consumerId = isset($_POST['consumer_id']) ? (int) $_POST['consumer_id'] : 0;
  $regenerateResult = acme_api_consumer_regenerate_key($consumerId);

  if (is_wp_error($regenerateResult)) {
    acme_api_consumer_store_admin_notice('error', $regenerateResult->get_error_message());
    wp_safe_redirect(admin_url('admin.php?page=acme-api-consumers'));
    exit;
  }

  set_transient('acme_api_consumer_plain_key_' . get_current_user_id(), $regenerateResult, 300);
  acme_api_consumer_store_admin_notice('success', 'Chave regenerada com sucesso. A chave anterior foi invalidada.');
  wp_safe_redirect(admin_url('admin.php?page=acme-api-consumers'));
  exit;
});

add_action('admin_post_acme_api_consumer_revoke', function () {
  if (!current_user_can('manage_options')) {
    wp_die('Sem permissão.');
  }

  check_admin_referer('acme_api_consumer_revoke');

  $consumerId = isset($_POST['consumer_id']) ? (int) $_POST['consumer_id'] : 0;
  $revoked = acme_api_consumer_revoke($consumerId);

  if (!$revoked) {
    acme_api_consumer_store_admin_notice('error', 'Não foi possível revogar a chave.');
    wp_safe_redirect(admin_url('admin.php?page=acme-api-consumers'));
    exit;
  }

  acme_api_consumer_store_admin_notice('success', 'Chave revogada com sucesso.');
  wp_safe_redirect(admin_url('admin.php?page=acme-api-consumers'));
  exit;
});

if (!function_exists('acme_api_consumer_store_admin_notice')) {
  function acme_api_consumer_store_admin_notice(string $noticeType, string $noticeMessage): void
  {
    set_transient('acme_api_consumer_notice_' . get_current_user_id(), [
      'type' => $noticeType,
      'message' => $noticeMessage,
    ], 60);
  }
}

/**
 * Consulta de saldo do fornecedor
 */
add_action('admin_post_acme_provider_balance_refresh', function () {
  if (!current_user_can('manage_options')) {
    wp_die('Sem permissão.');
  }

  check_admin_referer('acme_provider_balance_refresh');

  if (function_exists('acme_provider_balance_clear_cache')) {
    acme_provider_balance_clear_cache();
  }

  $balanceResult = function_exists('acme_provider_balance_get')
    ? acme_provider_balance_get(true)
    : new WP_Error('acme_provider_balance_unavailable', 'Serviço de saldo indisponível.');

  if (is_wp_error($balanceResult)) {
    acme_api_consumer_store_admin_notice('error', $balanceResult->get_error_message());
  } else {
    acme_api_consumer_store_admin_notice('success', 'Saldo do fornecedor atualizado com sucesso.');
  }

  wp_safe_redirect(admin_url('admin.php?page=acme-api-consumers'));
  exit;
});


if (!function_exists('acme_api_consumers_admin_page')) {
  function acme_api_consumers_admin_page(): void
  {
    if (!current_user_can('manage_options')) {
      wp_die('Sem permissão.');
    }

    acme_api_consumers_activate();

    $noticeData = get_transient('acme_api_consumer_notice_' . get_current_user_id());
    if ($noticeData) {
      delete_transient('acme_api_consumer_notice_' . get_current_user_id());
    }

    $plainKeyData = get_transient('acme_api_consumer_plain_key_' . get_current_user_id());
    if ($plainKeyData) {
      delete_transient('acme_api_consumer_plain_key_' . get_current_user_id());
    }

    $userList = get_users([
      'orderby' => 'display_name',
      'order' => 'ASC',
      'number' => 500,
      'fields' => ['ID', 'display_name', 'user_email', 'user_login'],
    ]);

    $consumerRows = acme_api_consumer_get_all(200);

    $providerBalanceData = function_exists('acme_provider_balance_get')
      ? acme_provider_balance_get(false)
      : new WP_Error('acme_provider_balance_unavailable', 'Serviço de saldo indisponível.');

    echo '<div class="wrap">';
    echo '<h1>Chaves da API</h1>';
    echo '<p>Crie e revogue chaves por usuário sem editar arquivos do plugin.</p>';

    if (!empty($noticeData['message'])) {
      $noticeClass = ($noticeData['type'] ?? '') === 'error' ? 'notice notice-error' : 'notice notice-success';
      echo '<div class="' . esc_attr($noticeClass) . '"><p>' . esc_html($noticeData['message']) . '</p></div>';
    }

    if (is_array($plainKeyData) && !empty($plainKeyData['api_key'])) {
      echo '<div class="notice notice-warning"><p><strong>Guarde esta chave agora.</strong> Ela só é exibida uma vez.</p>';
      echo '<p><code style="font-size:14px;">' . esc_html($plainKeyData['api_key']) . '</code></p>';
      echo '<p>Consumidor: <strong>' . esc_html((string) ($plainKeyData['consumer_name'] ?? '')) . '</strong> | Usuário WP: <strong>#' . (int) ($plainKeyData['wp_user_id'] ?? 0) . '</strong></p></div>';
    }

        echo '<div class="card" style="max-width:900px;padding:20px;margin-top:16px;">';
    echo '<h2>Saldo no fornecedor - Nova Era: INSS </h2>';

    if (is_wp_error($providerBalanceData)) {
      echo '<p><strong>Status:</strong> <span style="color:#b32d2e;">indisponível</span></p>';
      echo '<p>' . esc_html($providerBalanceData->get_error_message()) . '</p>';
    } else {
      $supplierBalance = (int) ($providerBalanceData['saldo'] ?? 0);
      $fetchedAt = (string) ($providerBalanceData['fetched_at'] ?? '');

      echo '<p style="font-size:28px;font-weight:700;margin:0 0 10px 0;">' . number_format_i18n($supplierBalance) . ' créditos</p>';

      if ($fetchedAt !== '') {
        echo '<p style="margin:0 0 12px 0;">Última atualização: <strong>' . esc_html(date_i18n('d/m/Y H:i:s', strtotime($fetchedAt))) . '</strong></p>';
      }
    }

    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
    wp_nonce_field('acme_provider_balance_refresh');
    echo '<input type="hidden" name="action" value="acme_provider_balance_refresh">';
    submit_button('Atualizar saldo agora', 'secondary', '', false);
    echo '</form>';

    echo '</div>';

    echo '<div class="card" style="max-width:900px;padding:20px;margin-top:16px;">';
    echo '<h2>Criar nova chave</h2>';
    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
    wp_nonce_field('acme_api_consumer_create');
    echo '<input type="hidden" name="action" value="acme_api_consumer_create">';

    echo '<table class="form-table" role="presentation"><tbody>';

    echo '<tr><th scope="row"><label for="acme_wp_user_id">Usuário WordPress</label></th><td>';
    echo '<select id="acme_wp_user_id" name="wp_user_id" required style="min-width:420px;">';
    echo '<option value="">Selecione um usuário</option>';
    foreach ($userList as $userItem) {
      $userLabel = sprintf('#%d — %s (%s)', (int) $userItem->ID, (string) $userItem->display_name, (string) $userItem->user_email ?: (string) $userItem->user_login);
      echo '<option value="' . (int) $userItem->ID . '">' . esc_html($userLabel) . '</option>';
    }
    echo '</select>';
    echo '<p class="description">A chave ficará vinculada a este usuário para crédito, rastreabilidade e auditoria.</p>';
    echo '</td></tr>';

    echo '<tr><th scope="row"><label for="acme_consumer_name">Nome do consumidor</label></th><td>';
    echo '<input id="acme_consumer_name" name="consumer_name" type="text" class="regular-text" placeholder="Ex.: Lucian ERP" required>';
    echo '<p class="description">Nome interno para você identificar o parceiro ou integração.</p>';
    echo '</td></tr>';

    echo '<tr><th scope="row">Serviços liberados</th><td>';
    echo '<label><input type="checkbox" name="allowed_services[]" value="clt" checked> CLT</label>';
    echo '<p class="description">Neste primeiro passo, a tela libera somente o serviço CLT.</p>';
    echo '</td></tr>';

    echo '</tbody></table>';

    submit_button('Gerar chave');
    echo '</form>';
    echo '</div>';

    echo '<div class="card" style="max-width:1200px;padding:20px;margin-top:20px;">';
    echo '<h2>Consumidores cadastrados</h2>';

    if (empty($consumerRows)) {
      echo '<p>Nenhuma chave cadastrada até o momento.</p>';
    } else {
      echo '<table class="widefat striped">';
      echo '<thead><tr>';
      echo '<th>ID</th><th>Consumidor</th><th>Usuário WP</th><th>Serviços</th><th>Status</th><th>Prefixo</th><th>Último uso</th><th>Criado em</th><th>Ações</th>';
      echo '</tr></thead><tbody>';

      foreach ($consumerRows as $consumerRow) {
        $wpUserId = (int) ($consumerRow['wp_user_id'] ?? 0);
        $linkedUser = $wpUserId > 0 ? get_user_by('id', $wpUserId) : false;
        $linkedUserLabel = $linkedUser ? sprintf('#%d — %s', $wpUserId, $linkedUser->display_name) : '#' . $wpUserId . ' — usuário não encontrado';

        echo '<tr>';
        echo '<td>' . (int) $consumerRow['id'] . '</td>';
        echo '<td><strong>' . esc_html((string) ($consumerRow['consumer_name'] ?? '')) . '</strong></td>';
        echo '<td>' . esc_html($linkedUserLabel) . '</td>';
        echo '<td>' . esc_html((string) ($consumerRow['allowed_services'] ?? '')) . '</td>';
        echo '<td>' . esc_html((string) ($consumerRow['status'] ?? '')) . '</td>';
        echo '<td><code>' . esc_html((string) ($consumerRow['api_key_prefix'] ?? '')) . '...</code></td>';
        echo '<td>' . esc_html((string) ($consumerRow['last_used_at'] ?? '—')) . '</td>';
        echo '<td>' . esc_html((string) ($consumerRow['created_at'] ?? '—')) . '</td>';
        echo '<td style="white-space:nowrap;">';

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline-block;margin-right:8px;">';
        wp_nonce_field('acme_api_consumer_regenerate');
        echo '<input type="hidden" name="action" value="acme_api_consumer_regenerate">';
        echo '<input type="hidden" name="consumer_id" value="' . (int) $consumerRow['id'] . '">';
        submit_button('Regenerar', 'secondary small', '', false, [
          'onclick' => "return confirm('Regenerar esta chave? A chave atual será invalidada imediatamente.');",
        ]);
        echo '</form>';

        if (($consumerRow['status'] ?? '') !== 'revoked') {
          echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline-block;">';
          wp_nonce_field('acme_api_consumer_revoke');
          echo '<input type="hidden" name="action" value="acme_api_consumer_revoke">';
          echo '<input type="hidden" name="consumer_id" value="' . (int) $consumerRow['id'] . '">';
          submit_button('Revogar', 'delete small', '', false, [
            'onclick' => "return confirm('Revogar esta chave? O consumo externo será bloqueado imediatamente.');",
          ]);
          echo '</form>';
        }

        echo '</td>';
        echo '</tr>';
      }

      echo '</tbody></table>';
    }

    echo '</div>';
    echo '</div>';
  }
}
