<?php
if (!defined('ABSPATH')) exit;

add_action('admin_menu', function () {
  add_menu_page(
    'Teste Presença Bank',
    'Teste Presença',
    'manage_options',
    'acme-presenca-test',
    'acme_presenca_test_page'
  );
});

function acme_presenca_test_page()
{
  if (!current_user_can('manage_options')) {
    echo '<div class="notice notice-error"><p>Sem permissão.</p></div>';
    return;
  }

  require_once __DIR__ . '/presenca_client.php';

  $out = null;

  if (isset($_POST['acme_presenca_test']) && check_admin_referer('acme_presenca_test')) {

    $login = sanitize_text_field($_POST['pb_login'] ?? '');
    $senha = sanitize_text_field($_POST['pb_senha'] ?? '');

    // 1) LOGIN
    $resLogin = acme_pb_login($login, $senha);

    if (!empty($resLogin['ok']) && !empty($resLogin['token'])) {

      // 2) NEGOTIATE no presenca-bank-api (hub)
      $neg = acme_pb_request(
        'POST',
        'https://presenca-bank-api.azurewebsites.net',
        '/consulta-beneficio-hub/negotiate?negotiateVersion=1',
        $resLogin['token']
      );

      if (!empty($neg['ok']) && !empty($neg['data']['url']) && !empty($neg['data']['accessToken'])) {

        // 3) Azure SignalR negotiate (pegar connectionId/connectionToken)
        $az = acme_pb_signalr_negotiate_azure($neg['data']);

        if (!empty($az['ok']) && !empty($az['data'])) {

          // 4) Connect via LongPolling (id obrigatório)
          $conn = acme_pb_signalr_connect($neg['data'], $az['data']);

          $out = [
            'login'           => $resLogin,
            'negotiate'       => $neg,
            'azure_negotiate' => $az,
            'connect'         => $conn,
          ];

        } else {

          $out = [
            'login'           => $resLogin,
            'negotiate'       => $neg,
            'azure_negotiate' => $az,
          ];
        }

      } else {

        $out = [
          'login'     => $resLogin,
          'negotiate' => $neg,
        ];
      }

    } else {

      $out = [
        'login' => $resLogin,
      ];
    }
  }

  echo '<div class="wrap"><h1>Teste Presença Bank (SignalR)</h1>';
  echo '<form method="post">';
  wp_nonce_field('acme_presenca_test');

  echo '<table class="form-table"><tbody>';
  echo '<tr><th><label>Login</label></th><td><input type="text" name="pb_login" class="regular-text" autocomplete="off" /></td></tr>';
  echo '<tr><th><label>Senha</label></th><td><input type="password" name="pb_senha" class="regular-text" autocomplete="off" /></td></tr>';
  echo '</tbody></table>';

  echo '<p><button class="button button-primary" name="acme_presenca_test" value="1">Testar Fluxo</button></p>';
  echo '</form>';

  if ($out !== null) {

    // 🔒 Mascarar tokens pra não vazar em tela/log
    if (!empty($out['login']['token'])) {
      $out['login']['token'] = substr($out['login']['token'], 0, 12) . '...';
    }

    if (!empty($out['negotiate']['data']['accessToken'])) {
      $out['negotiate']['data']['accessToken'] = substr($out['negotiate']['data']['accessToken'], 0, 12) . '...';
    }

    // opcional: mascarar cpf também
    if (!empty($out['login']['usuario']['cpf'])) {
      $out['login']['usuario']['cpf'] = '***';
    }

    echo '<h2>Resultado</h2>';
    echo '<pre style="background:#fff;border:1px solid #ddd;padding:12px;max-width:980px;white-space:pre-wrap;">';
    echo esc_html(print_r($out, true));
    echo '</pre>';
  }

  echo '</div>';
}
