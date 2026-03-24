<?php
if (!defined('ABSPATH')) exit;

/**
 * ============================================================
 * ADMIN MENU + PÁGINA "DAR CRÉDITOS"
 * Mantém a lógica original (mesmos slugs/actions/capability)
 * ============================================================
 */

if (!function_exists('acme_credits_admin_menu')) {
  add_action('admin_menu', 'acme_credits_admin_menu');

  function acme_credits_admin_menu() {
    // Menu raiz
    add_menu_page(
      'ACME',
      'ACME',
      'manage_options',
      'acme_root',
      'acme_root_page',
      'dashicons-shield',
      58
    );

    // Submenu Créditos
    add_submenu_page(
      'acme_root',
      'Créditos',
      'Créditos',
      'manage_options',
      'acme_credits',
      'acme_credits_admin_page'
    );
  }

  function acme_root_page() {
    echo '<div class="wrap"><h1>ACME</h1><p>Use o menu ao lado.</p></div>';
  }
}

/**
 * Render da página admin: Créditos
 */
if (!function_exists('acme_credits_admin_page')) {
  function acme_credits_admin_page() {
    if (!current_user_can('manage_options')) {
      wp_die('Sem permissão.');
    }

    global $wpdb;

    // Dados (Serviços)
    $servicesT = acme_table_services();
    $services  = $wpdb->get_results("SELECT slug, name, credits_cost FROM {$servicesT} ORDER BY name ASC");

    // Dados (Usuários)
    // lista filhos + netos (se quiser restringir só filhos, troque roles abaixo)
    $users = get_users([
      'role__in' => ['child', 'grandchild'],
      'orderby'  => 'display_name',
      'order'    => 'ASC',
      'number'   => 500,
    ]);

    // Mensagens
    $msg = isset($_GET['acme_msg']) ? sanitize_text_field($_GET['acme_msg']) : '';
    ?>
    <div class="wrap">
      <h1>Créditos</h1>

      <?php if ($msg === 'ok'): ?>
        <div class="notice notice-success">
          <p><strong>Créditos concedidos com sucesso.</strong></p>
        </div>
      <?php elseif ($msg === 'err'): ?>
        <div class="notice notice-error">
          <p><strong>Ação negada.</strong></p>
        </div>
      <?php endif; ?>

      <h2>Dar créditos</h2>

      <form
        method="post"
        action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
        style="max-width:720px;background:#fff;border:1px solid #ddd;padding:16px;border-radius:10px"
      >
        <input type="hidden" name="action" value="acme_admin_grant_credits">
        <?php wp_nonce_field('acme_admin_grant_credits'); ?>

        <table class="form-table" role="presentation">
          <tr>
            <th scope="row"><label for="acme_user_id">Usuário</label></th>
            <td>
              <select id="acme_user_id" name="user_id" required style="min-width:360px">
                <option value="">Selecione...</option>
                <?php foreach ((array)$users as $u): ?>
                  <option value="<?php echo (int) $u->ID; ?>">
                    <?php echo esc_html($u->display_name . ' (#' . $u->ID . ') - ' . $u->user_email); ?>
                  </option>
                <?php endforeach; ?>
              </select>
              <p class="description">Você pode conceder para Master ou Sub-Login.</p>
            </td>
          </tr>

          <tr>
            <th scope="row"><label for="acme_service_slug">Serviço</label></th>
            <td>
              <select id="acme_service_slug" name="service_slug" required style="min-width:360px">
                <option value="">Selecione...</option>
                <?php foreach ((array)$services as $s): ?>
                  <option value="<?php echo esc_attr($s->slug); ?>">
                    <?php echo esc_html($s->name . ' (' . (int) $s->credits_cost . ' crédito(s) por uso)'); ?>
                  </option>
                <?php endforeach; ?>
              </select>
              <p class="description">
                Configurado na tabela <code>wp_services</code> (campo <code>credits_cost</code>).
              </p>
            </td>
          </tr>

          <tr>
            <th scope="row"><label for="acme_credits_qty">Quantidade de créditos</label></th>
            <td>
              <input id="acme_credits_qty" type="number" name="credits" min="1" step="1" required value="1" style="width:120px">
            </td>
          </tr>

          <tr>
            <th scope="row"><label for="acme_expires_at">Expiração (opcional)</label></th>
            <td>
              <input id="acme_expires_at" type="date" name="expires_at">
              <p class="description">Se preenchido, salva como <code>YYYY-MM-DD 23:59:59</code> na wallet.</p>
            </td>
          </tr>

          <tr>
            <th scope="row"><label for="acme_notes">Observação (opcional)</label></th>
            <td>
              <input id="acme_notes" type="text" name="notes" placeholder="Ex: pacote mensal" style="min-width:360px">
            </td>
          </tr>
        </table>

        <p>
          <button type="submit" class="button button-primary">Conceder créditos</button>
        </p>
      </form>
      <hr style="margin:28px 0;">

      <h2>Recuperar créditos (Admin → Master)</h2>

      <form
        method="post"
        action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
        style="max-width:720px;background:#fff;border:1px solid #ddd;padding:16px;border-radius:10px"
      >
        <input type="hidden" name="action" value="acme_recover_credits">
        <?php wp_nonce_field('acme_recover_credits'); ?>

        <table class="form-table" role="presentation">
          <tr>
            <th scope="row"><label for="acme_recover_user_id">Master</label></th>
            <td>
              <?php
                $masters = get_users([
                  'role__in' => ['child'],
                  'orderby'  => 'display_name',
                  'order'    => 'ASC',
                  'number'   => 500,
                ]);
              ?>
              <select id="acme_recover_user_id" name="user_id" required style="min-width:360px">
                <option value="">Selecione...</option>
                <?php foreach ((array)$masters as $u): ?>
                  <option value="<?php echo (int) $u->ID; ?>">
                    <?php echo esc_html($u->display_name . ' (#' . $u->ID . ') - ' . $u->user_email); ?>
                  </option>
                <?php endforeach; ?>
              </select>
              <p class="description">Remove créditos disponíveis do Master (não afeta créditos já utilizados).</p>
            </td>
          </tr>

          <tr>
            <th scope="row"><label for="acme_recover_service_slug">Serviço</label></th>
            <td>
              <select id="acme_recover_service_slug" name="service_slug" required style="min-width:360px">
                <option value="">Selecione...</option>
                <?php foreach ((array)$services as $s): ?>
                  <option value="<?php echo esc_attr($s->slug); ?>">
                    <?php echo esc_html($s->name . ' (' . (int) $s->credits_cost . ' crédito(s) por uso)'); ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </td>
          </tr>

          <tr>
            <th scope="row"><label for="acme_recover_credits_qty">Quantidade</label></th>
            <td>
              <input id="acme_recover_credits_qty" type="number" name="credits" min="1" step="1" required value="1" style="width:120px">
            </td>
          </tr>

          <tr>
            <th scope="row"><label for="acme_recover_notes">Observação (opcional)</label></th>
            <td>
              <input id="acme_recover_notes" type="text" name="notes" placeholder="Ex: ajuste de pacote" style="min-width:360px">
            </td>
          </tr>
        </table>

        <p>
          <button type="submit" class="button">Recuperar créditos</button>
        </p>
      </form>

    </div>
    <?php
  }
}
