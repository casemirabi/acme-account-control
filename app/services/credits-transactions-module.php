<?php
if (!defined('ABSPATH')) exit;

/**
 * ============================================================
 * CRÉDITOS: Histórico e Gestão de Transações (ADMIN)
 * - Lista/filtra transações
 * - Mostra resumo por usuário: total/usado/disponível (wallet)
 * - Permite gerenciar: status, tentativas, notas
 * ============================================================
 */

if (!function_exists('acme_table_services')) {
  function acme_table_services(): string { global $wpdb; return $wpdb->prefix . 'services'; }
}
if (!function_exists('acme_table_wallet')) {
  function acme_table_wallet(): string { global $wpdb; return $wpdb->prefix . 'wallet'; }
}
if (!function_exists('acme_table_credit_tx')) {
  function acme_table_credit_tx(): string { global $wpdb; return $wpdb->prefix . 'credit_transactions'; }
}

/**
 * Resumo por usuário (totais/usados/disponível) + estatísticas de tx
 */
if (!function_exists('acme_credits_user_summary')) {
  function acme_credits_user_summary(int $user_id): array {
    global $wpdb;
    $walletT = acme_table_wallet();
    $txT     = acme_table_credit_tx();

    // Wallet agregado (por usuário, somando todos os serviços)
    $w = $wpdb->get_row($wpdb->prepare("
      SELECT
        COALESCE(SUM(credits_total),0) AS total,
        COALESCE(SUM(credits_used),0)  AS used
      FROM {$walletT}
      WHERE master_user_id = %d
    ", $user_id));

    $total = (int)($w->total ?? 0);
    $used  = (int)($w->used ?? 0);
    $avail = max(0, $total - $used);

    // Estatísticas de transações
    $s = $wpdb->get_row($wpdb->prepare("
      SELECT
        COUNT(*) AS tx_count,
        COALESCE(SUM(CASE WHEN status='failed' THEN 1 ELSE 0 END),0) AS failed_count,
        COALESCE(SUM(CASE WHEN status='success' THEN 1 ELSE 0 END),0) AS success_count,
        COALESCE(SUM(CASE WHEN type='debit' AND status='success' THEN credits ELSE 0 END),0) AS debited_success,
        COALESCE(SUM(CASE WHEN type='credit' AND status='success' THEN credits ELSE 0 END),0) AS credited_success
      FROM {$txT}
      WHERE user_id = %d
    ", $user_id));

    return [
      'total' => $total,
      'used' => $used,
      'available' => $avail,
      'tx_count' => (int)($s->tx_count ?? 0),
      'failed_count' => (int)($s->failed_count ?? 0),
      'success_count' => (int)($s->success_count ?? 0),
      'debited_success' => (int)($s->debited_success ?? 0),
      'credited_success' => (int)($s->credited_success ?? 0),
    ];
  }
}

/**
 * Registrar uma transação (helper para você chamar quando consumir/debitar etc.)
 * - snapshot do serviço (slug/nome) para o histórico não “quebrar” se renomear depois
 */
if (!function_exists('acme_credits_tx_log')) {
  function acme_credits_tx_log(array $data): int {
    global $wpdb;
    $txT = acme_table_credit_tx();

    $now = current_time('mysql');

    $defaults = [
      'user_id' => 0,
      'service_id' => null,
      'service_slug' => null,
      'service_name' => null,
      'type' => 'debit',        // credit|debit|refund|adjust|attempt
      'credits' => 0,
      'status' => 'success',    // pending|success|failed|canceled
      'attempts' => 1,
      'request_id' => null,
      'actor_user_id' => get_current_user_id() ?: null,
      'notes' => null,
      'meta' => null,
      'created_at' => $now,
    ];

    $d = array_merge($defaults, $data);

    $d['user_id'] = (int)$d['user_id'];
    $d['credits'] = (int)$d['credits'];
    $d['attempts'] = max(1, (int)$d['attempts']);

    if ($d['user_id'] <= 0) return 0;

    if (is_array($d['meta'])) {
      $d['meta'] = wp_json_encode($d['meta'], JSON_UNESCAPED_UNICODE);
    }

    $ok = $wpdb->insert($txT, [
      'user_id' => $d['user_id'],
      'service_id' => $d['service_id'],
      'service_slug' => $d['service_slug'],
      'service_name' => $d['service_name'],
      'type' => $d['type'],
      'credits' => $d['credits'],
      'status' => $d['status'],
      'attempts' => $d['attempts'],
      'request_id' => $d['request_id'],
      'actor_user_id' => $d['actor_user_id'],
      'notes' => $d['notes'],
      'meta' => $d['meta'],
      'created_at' => $d['created_at'],
    ]);

    return $ok ? (int)$wpdb->insert_id : 0;
  }
}

/**
 * Menu: Créditos > Transações (fica junto do módulo de serviços)
 * Observação: se o menu "Créditos" já existir, isto só adiciona submenu.
 */
add_action('admin_menu', function () {
  if (!current_user_can('manage_options')) return;

  add_submenu_page(
    'acme-credits',
    'Transações',
    'Transações',
    'manage_options',
    'acme-credits-transactions',
    'acme_credits_transactions_page'
  );
});

/**
 * Actions: atualizar status / incrementar tentativas / atualizar notas
 */
add_action('admin_post_acme_credits_tx_update', function () {
  if (!current_user_can('manage_options')) wp_die('Sem permissão.');
  check_admin_referer('acme_credits_tx_update');

  $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
  if ($id <= 0) wp_die('ID inválido.');

  $status = isset($_POST['status']) ? sanitize_text_field($_POST['status']) : '';
  $notes  = isset($_POST['notes']) ? wp_kses_post($_POST['notes']) : null;

  $allowed_status = ['pending','success','failed','canceled'];
  if (!in_array($status, $allowed_status, true)) $status = 'pending';

  global $wpdb;
  $txT = acme_table_credit_tx();

  $wpdb->update($txT, [
    'status' => $status,
    'notes'  => $notes,
  ], ['id' => $id]);

  wp_safe_redirect(add_query_arg(['page'=>'acme-credits-transactions','acme_msg'=>'updated'], admin_url('admin.php')));
  exit;
});

add_action('admin_post_acme_credits_tx_attempt', function () {
  if (!current_user_can('manage_options')) wp_die('Sem permissão.');
  check_admin_referer('acme_credits_tx_attempt');

  $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
  if ($id <= 0) wp_die('ID inválido.');

  global $wpdb;
  $txT = acme_table_credit_tx();
  $wpdb->query($wpdb->prepare("UPDATE {$txT} SET attempts = attempts + 1 WHERE id=%d", $id));

  wp_safe_redirect(add_query_arg(['page'=>'acme-credits-transactions','acme_msg'=>'attempt'], admin_url('admin.php')));
  exit;
});

/**
 * Página: listagem + filtros + resumo por usuário
 */
if (!function_exists('acme_credits_transactions_page')) {
  function acme_credits_transactions_page() {
    if (!current_user_can('manage_options')) wp_die('Sem permissão.');

    global $wpdb;
    $txT = acme_table_credit_tx();

    // Filtros
    $user_id = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0;
    $type    = isset($_GET['type']) ? sanitize_text_field($_GET['type']) : '';
    $status  = isset($_GET['status']) ? sanitize_text_field($_GET['status']) : '';
    $service = isset($_GET['service']) ? sanitize_text_field($_GET['service']) : '';
    $from    = isset($_GET['from']) ? sanitize_text_field($_GET['from']) : '';
    $to      = isset($_GET['to']) ? sanitize_text_field($_GET['to']) : '';

    $page_num = isset($_GET['paged']) ? max(1, (int)$_GET['paged']) : 1;
    $per_page = 25;
    $offset   = ($page_num - 1) * $per_page;

    $where = "WHERE 1=1";
    $args  = [];

    if ($user_id > 0) {
      $where .= " AND user_id=%d";
      $args[] = $user_id;
    }
    if ($type !== '') {
      $where .= " AND type=%s";
      $args[] = $type;
    }
    if ($status !== '') {
      $where .= " AND status=%s";
      $args[] = $status;
    }
    if ($service !== '') {
      $where .= " AND service_slug=%s";
      $args[] = $service;
    }
    // Datas (YYYY-MM-DD)
    if ($from !== '') {
      $where .= " AND created_at >= %s";
      $args[] = $from . " 00:00:00";
    }
    if ($to !== '') {
      $where .= " AND created_at <= %s";
      $args[] = $to . " 23:59:59";
    }

    // Total rows p/ paginação
    $count_sql = "SELECT COUNT(*) FROM {$txT} {$where}";
    $total_rows = (int) $wpdb->get_var($args ? $wpdb->prepare($count_sql, $args) : $count_sql);

    // Rows
    $sql = "SELECT * FROM {$txT} {$where} ORDER BY id DESC LIMIT {$per_page} OFFSET {$offset}";
    $rows = $args ? $wpdb->get_results($wpdb->prepare($sql, $args)) : $wpdb->get_results($sql);

    // Resumo por usuário (se filtrado)
    $summary = null;
    $user_obj = null;
    if ($user_id > 0) {
      $user_obj = get_user_by('id', $user_id);
      $summary = acme_credits_user_summary($user_id);
    }

    $base_url = admin_url('admin.php?page=acme-credits-transactions');
    ?>
    <div class="wrap">
      <h1>Créditos • Transações</h1>
      <p>Histórico completo: tipo, quem, quando, tentativas, status e vínculo com serviço. Use filtros para auditoria.</p>

      <?php if (isset($_GET['acme_msg']) && $_GET['acme_msg'] === 'updated'): ?>
        <div class="notice notice-success"><p>Transação atualizada.</p></div>
      <?php elseif (isset($_GET['acme_msg']) && $_GET['acme_msg'] === 'attempt'): ?>
        <div class="notice notice-success"><p>Tentativa incrementada.</p></div>
      <?php endif; ?>

      <form method="get" style="margin: 14px 0; display:flex; gap:10px; flex-wrap:wrap; align-items:flex-end;">
        <input type="hidden" name="page" value="acme-credits-transactions">

        <div>
          <label style="display:block;font-size:12px;opacity:.8">User ID</label>
          <input type="number" name="user_id" value="<?php echo esc_attr($user_id ?: ''); ?>" style="width:120px">
        </div>

        <div>
          <label style="display:block;font-size:12px;opacity:.8">Tipo</label>
          <select name="type">
            <option value="">Todos</option>
            <?php foreach (['credit','debit','refund','adjust','attempt'] as $t): ?>
              <option value="<?php echo esc_attr($t); ?>" <?php selected($type, $t); ?>><?php echo esc_html($t); ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div>
          <label style="display:block;font-size:12px;opacity:.8">Status</label>
          <select name="status">
            <option value="">Todos</option>
            <?php foreach (['pending','success','failed','canceled'] as $s): ?>
              <option value="<?php echo esc_attr($s); ?>" <?php selected($status, $s); ?>><?php echo esc_html($s); ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div>
          <label style="display:block;font-size:12px;opacity:.8">Serviço (slug)</label>
          <input type="text" name="service" value="<?php echo esc_attr($service); ?>" placeholder="clt" style="width:160px">
        </div>

        <div>
          <label style="display:block;font-size:12px;opacity:.8">De</label>
          <input type="date" name="from" value="<?php echo esc_attr($from); ?>">
        </div>

        <div>
          <label style="display:block;font-size:12px;opacity:.8">Até</label>
          <input type="date" name="to" value="<?php echo esc_attr($to); ?>">
        </div>

        <button class="button button-primary" type="submit">Filtrar</button>
        <a class="button" href="<?php echo esc_url($base_url); ?>">Limpar</a>
      </form>

      <?php if ($summary && $user_obj): ?>
        <div style="border:1px solid #dcdcde; background:#fff; border-radius:10px; padding:12px; margin: 10px 0 18px;">
          <h2 style="margin:0 0 10px 0;">Resumo do usuário #<?php echo (int)$user_id; ?> — <?php echo esc_html($user_obj->display_name ?: $user_obj->user_login); ?></h2>
          <div style="display:flex; gap:14px; flex-wrap:wrap;">
            <div><strong>Total:</strong> <?php echo (int)$summary['total']; ?></div>
            <div><strong>Usados:</strong> <?php echo (int)$summary['used']; ?></div>
            <div><strong>Disponíveis:</strong> <?php echo (int)$summary['available']; ?></div>
            <div><strong>Tx:</strong> <?php echo (int)$summary['tx_count']; ?></div>
            <div><strong>Falhas:</strong> <?php echo (int)$summary['failed_count']; ?></div>
            <div><strong>Débitos OK:</strong> <?php echo (int)$summary['debited_success']; ?></div>
            <div><strong>Créditos OK:</strong> <?php echo (int)$summary['credited_success']; ?></div>
          </div>
          <p style="margin:10px 0 0 0; opacity:.75; font-size:12px;">
            * Total/Usados/Disponíveis vêm da tabela wallet (somando todos os serviços). O histórico abaixo é o log.
          </p>
        </div>
      <?php endif; ?>

      <table class="widefat striped">
        <thead>
          <tr>
            <th>ID</th>
            <th>Quando</th>
            <th>Usuário</th>
            <th>Serviço</th>
            <th>Tipo</th>
            <th>Créditos</th>
            <th>Status</th>
            <th>Tentativas</th>
            <th>Request ID</th>
            <th>Gerenciar</th>
          </tr>
        </thead>
        <tbody>
          <?php if (!$rows): ?>
            <tr><td colspan="10">Nenhuma transação encontrada.</td></tr>
          <?php else: foreach ($rows as $r):
            $u = get_user_by('id', (int)$r->user_id);
            $u_label = $u ? ($u->display_name ?: $u->user_login) : '—';
            $actor = $r->actor_user_id ? get_user_by('id', (int)$r->actor_user_id) : null;
            $actor_label = $actor ? ($actor->display_name ?: $actor->user_login) : '—';
          ?>
            <tr>
              <td><?php echo (int)$r->id; ?></td>
              <td>
                <?php echo esc_html($r->created_at); ?><br>
                <span style="opacity:.7;font-size:12px;">por: <?php echo esc_html($actor_label); ?></span>
              </td>
              <td>
                #<?php echo (int)$r->user_id; ?> — <?php echo esc_html($u_label); ?>
                <div style="margin-top:4px;">
                  <a href="<?php echo esc_url(add_query_arg(['user_id'=>(int)$r->user_id], $base_url)); ?>">ver usuário</a>
                </div>
              </td>
              <td>
                <?php echo $r->service_slug ? '<code>'.esc_html($r->service_slug).'</code>' : '—'; ?><br>
                <span style="opacity:.8;font-size:12px;"><?php echo esc_html($r->service_name ?: ''); ?></span>
              </td>
              <td><code><?php echo esc_html($r->type); ?></code></td>
              <td><strong><?php echo (int)$r->credits; ?></strong></td>
              <td><code><?php echo esc_html($r->status); ?></code></td>
              <td><?php echo (int)$r->attempts; ?></td>
              <td style="max-width:180px; word-break:break-word;"><?php echo esc_html($r->request_id ?: '—'); ?></td>
              <td>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-bottom:8px;">
                  <?php wp_nonce_field('acme_credits_tx_update'); ?>
                  <input type="hidden" name="action" value="acme_credits_tx_update">
                  <input type="hidden" name="id" value="<?php echo (int)$r->id; ?>">
                  <select name="status">
                    <?php foreach (['pending','success','failed','canceled'] as $s): ?>
                      <option value="<?php echo esc_attr($s); ?>" <?php selected($r->status, $s); ?>><?php echo esc_html($s); ?></option>
                    <?php endforeach; ?>
                  </select>
                  <input type="text" name="notes" value="<?php echo esc_attr($r->notes ?? ''); ?>" placeholder="Notas" style="width:180px;">
                  <button class="button" type="submit">Salvar</button>
                </form>

                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                  <?php wp_nonce_field('acme_credits_tx_attempt'); ?>
                  <input type="hidden" name="action" value="acme_credits_tx_attempt">
                  <input type="hidden" name="id" value="<?php echo (int)$r->id; ?>">
                  <button class="button">+1 tentativa</button>
                </form>
              </td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>

      <?php
        // Paginação simples
        $total_pages = (int)ceil($total_rows / $per_page);
        if ($total_pages > 1):
          $qargs = $_GET;
          ?>
          <div style="margin-top:12px; display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
            <strong>Páginas:</strong>
            <?php for ($p=1; $p<=$total_pages; $p++):
              $qargs['paged'] = $p;
              $url = add_query_arg($qargs, admin_url('admin.php'));
              ?>
              <a class="button <?php echo ($p===$page_num)?'button-primary':''; ?>" href="<?php echo esc_url($url); ?>">
                <?php echo (int)$p; ?>
              </a>
            <?php endfor; ?>
            <span style="opacity:.7;">(<?php echo (int)$total_rows; ?> registros)</span>
          </div>
        <?php endif; ?>

    </div>
    <?php
  }
}
