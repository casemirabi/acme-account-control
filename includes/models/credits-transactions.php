<?php
if (!defined('ABSPATH')) exit;

/**
 * ============================================================
 * MÓDULO: Transações de Créditos
 * Shortcode: [acme_credit_transactions]
 * Export: Excel (.xls) e PDF (Dompdf com fallback)
 * ============================================================
 *
 * ✅ AJUSTES APLICADOS (pra você só colar):
 * - Remove CSS inline (acme_ui_panel_css + <style> extra)
 * - Adiciona enqueue do CSS externo (assets/css/acme-credit-transactions.css)
 * - Corrige bug: $q estava comentado e era usado
 * - Corrige bug: status usando $r->type === 'failed' (agora $r->status === 'failed')
 */

/**
 * ============================================================
 * Enqueue do CSS do módulo (front + admin)
 * Arquivo: assets/css/acme-credit-transactions.css (você já criou)
 * ============================================================
 */
if (!function_exists('acme_enqueue_credit_transactions_css')) {
  function acme_enqueue_credit_transactions_css(): void
  {
    // evita fatal se constantes não existirem
    if (!defined('ACME_ACC_PATH') || !defined('ACME_ACC_URL')) return;

    $css_rel  = 'assets/css/acme-credit-transactions.css';
    $css_path = trailingslashit(ACME_ACC_PATH) . $css_rel;
    $css_url  = trailingslashit(ACME_ACC_URL) . $css_rel;

    $css_ver = file_exists($css_path) ? (string) filemtime($css_path) : null;

    wp_register_style('acme-credit-transactions-style', $css_url, [], $css_ver);
    wp_enqueue_style('acme-credit-transactions-style');
  }

  add_action('wp_enqueue_scripts', 'acme_enqueue_credit_transactions_css');
  add_action('admin_enqueue_scripts', 'acme_enqueue_credit_transactions_css');
}

/**
 * ============================================================
 * Permissão (RBAC do módulo):
 * - Admin: OK
 * - Master (child): OK
 * - Sub-login (grandchild): OK
 * ============================================================
 */
if (!function_exists('acme_can_view_transactions')) {
  function acme_can_view_transactions(): bool
  {
    if (!is_user_logged_in()) return false;
    if (current_user_can('manage_options')) return true;

    $u = wp_get_current_user();
    $roles = (array) $u->roles;

    return in_array('child', $roles, true) || in_array('grandchild', $roles, true);
  }
}

/**
 * ============================================================
 * Escopo RBAC + lista permitida de usuários
 * - Admin: vê tudo
 * - Master (child): vê ele + netos vinculados (sub-logins)
 * - Sub-login (grandchild): vê só ele
 * ============================================================
 */
if (!function_exists('acme_tx_scope')) {
  function acme_tx_scope(int $viewer_id): array
  {
    $u = get_user_by('id', $viewer_id);
    $roles = $u ? (array) $u->roles : [];

    // Admin vê tudo
    if (user_can($viewer_id, 'manage_options')) {
      return [
        'mode' => 'admin',
        'allowed_ids' => [],
        'sql' => '1=1',
        'args' => [],
      ];
    }

    // Master (child): ele + netos dele
    if (in_array('child', $roles, true)) {
      $allowed = [$viewer_id];

      if (function_exists('acme_get_grandchildren_of_child')) {
        $ids = (array) acme_get_grandchildren_of_child($viewer_id);
        $allowed = array_merge($allowed, array_map('intval', $ids));
      }

      $allowed = array_values(array_unique(array_filter(array_map('intval', $allowed))));
      if (!$allowed) $allowed = [$viewer_id];

      $ph = implode(',', array_fill(0, count($allowed), '%d'));

      return [
        'mode' => 'child',
        'allowed_ids' => $allowed,
        'sql' => "t.user_id IN ($ph)",
        'args' => $allowed,
      ];
    }

    // Sub-login (grandchild): só ele
    if (in_array('grandchild', $roles, true)) {
      return [
        'mode' => 'grandchild',
        'allowed_ids' => [$viewer_id],
        'sql' => 't.user_id = %d',
        'args' => [$viewer_id],
      ];
    }

    // fallback: só ele
    return [
      'mode' => 'self',
      'allowed_ids' => [$viewer_id],
      'sql' => 't.user_id = %d',
      'args' => [$viewer_id],
    ];
  }
}

/**
 * ============================================================
 * EXPORT (Excel/PDF) — admin-post
 * - Respeita o MESMO escopo RBAC do relatório (acme_tx_scope)
 * - Reusa os mesmos filtros do GET
 * ============================================================
 */
if (!function_exists('acme_tx_read_filters')) {
  function acme_tx_read_filters(): array
  {
    return [
      'q' => isset($_GET['q']) ? trim((string) $_GET['q']) : '',
      'type' => isset($_GET['type']) ? sanitize_text_field((string) $_GET['type']) : '',
      'status' => isset($_GET['status']) ? sanitize_text_field((string) $_GET['status']) : '',
      'user_id' => isset($_GET['user_id']) ? (int) $_GET['user_id'] : 0,
      'service_id' => isset($_GET['service_id']) ? (int) $_GET['service_id'] : 0,
      'from' => isset($_GET['from']) ? sanitize_text_field((string) $_GET['from']) : '',
      'to' => isset($_GET['to']) ? sanitize_text_field((string) $_GET['to']) : '',
    ];
  }
}

if (!function_exists('acme_tx_fetch_rows')) {
  /**
   * Busca linhas de transações respeitando o escopo do viewer.
   * @return array{rows: array, total: int}
   */
  function acme_tx_fetch_rows(int $viewer_id, array $filters, int $limit = 30, int $offset = 0): array
  {
    global $wpdb;

    $txT = acme_table_credit_transactions();
    $usersT = $wpdb->users;
    $servicesT = acme_table_services();

    $q = (string) ($filters['q'] ?? '');
    $type = (string) ($filters['type'] ?? '');
    $status = (string) ($filters['status'] ?? '');
    $user_id = (int) ($filters['user_id'] ?? 0);
    $service_id = (int) ($filters['service_id'] ?? 0);
    $date_from = (string) ($filters['from'] ?? '');
    $date_to = (string) ($filters['to'] ?? '');

    $where = [];
    $args = [];

    $scope = acme_tx_scope($viewer_id);
    $is_admin_view = ($scope['mode'] === 'admin');
    $allowed_ids = $is_admin_view ? [] : (array) $scope['allowed_ids'];

    // trava no escopo RBAC
    $where[] = $scope['sql'];
    $args = array_merge($args, $scope['args']);

    // Se for master/sub, NÃO deixa forçar user_id fora do escopo
    if (!$is_admin_view && $user_id > 0 && !in_array($user_id, array_map('intval', $allowed_ids), true)) {
      $user_id = 0;
    }

    if ($q !== '') {
      $where[] = "(u.display_name LIKE %s OR u.user_email LIKE %s OR u.user_login LIKE %s OR t.request_id LIKE %s)";
      $like = '%' . $wpdb->esc_like($q) . '%';
      array_push($args, $like, $like, $like, $like);
    }

    // enums reais (conforme schema)
    if (in_array($type, ['credit', 'debit', 'refund', 'adjust', 'attempt'], true)) {
      $where[] = "t.type = %s";
      $args[] = $type;
    }

    if (in_array($status, ['pending', 'success', 'failed', 'canceled'], true)) {
      $where[] = "t.status = %s";
      $args[] = $status;
    }

    if ($user_id > 0) {
      $where[] = "t.user_id = %d";
      $args[] = $user_id;
    }

    if ($service_id > 0) {
      $where[] = "t.service_id = %d";
      $args[] = $service_id;
    }

    if ($date_from) {
      $where[] = "t.created_at >= %s";
      $args[] = $date_from . ' 00:00:00';
    }
    if ($date_to) {
      $where[] = "t.created_at <= %s";
      $args[] = $date_to . ' 23:59:59';
    }

    $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    $sqlCount = "
      SELECT COUNT(*)
      FROM {$txT} t
      INNER JOIN {$usersT} u ON u.ID = t.user_id
      LEFT JOIN {$servicesT} s ON s.id = t.service_id
      {$whereSql}
    ";
    $total = (int) $wpdb->get_var($wpdb->prepare($sqlCount, $args));

    $sql = "
      SELECT
        t.*,
        u.display_name, u.user_email,
        s.name AS service_name, s.slug AS service_slug
      FROM {$txT} t
      INNER JOIN {$usersT} u ON u.ID = t.user_id
      LEFT JOIN {$servicesT} s ON s.id = t.service_id
      {$whereSql}
      ORDER BY t.id DESC
      LIMIT %d OFFSET %d
    ";
    $rows = $wpdb->get_results($wpdb->prepare($sql, array_merge($args, [$limit, $offset])));

    return ['rows' => $rows, 'total' => $total];
  }
}

if (!function_exists('acme_tx_pdf_escape')) {
  function acme_tx_pdf_escape(string $s): string
  {
    return str_replace(['\\', '(', ')', "\r", "\n"], ['\\\\', '\\(', '\\)', ' ', ' '], $s);
  }
}

if (!function_exists('acme_tx_build_simple_pdf')) {
  /**
   * PDF simples (texto) — fallback caso Dompdf não esteja instalado.
   */
  function acme_tx_build_simple_pdf(string $title, array $lines): string
  {
    $pages = [];
    $per_page = 45;
    $chunks = array_chunk($lines, $per_page);

    foreach ($chunks as $page_idx => $chunk) {
      $y_start = 800;
      $line_h = 14;
      $x_left = 40;

      $content = "BT\n/F1 12 Tf\n";
      $content .= sprintf("1 0 0 1 %d %d Tm (%s) Tj\n", $x_left, $y_start, acme_tx_pdf_escape($title . ' — pág ' . ($page_idx + 1)));
      $y = $y_start - 24;

      foreach ($chunk as $ln) {
        $content .= sprintf("1 0 0 1 %d %d Tm (%s) Tj\n", $x_left, $y, acme_tx_pdf_escape($ln));
        $y -= $line_h;
      }
      $content .= "ET";
      $pages[] = $content;
    }

    $objects = [];
    $offsets = [];
    $pdf = "%PDF-1.4\n";

    $objects[] = "<< /Type /Catalog /Pages 2 0 R >>";

    $kids = [];
    $page_obj_start = 3;
    for ($i = 0; $i < count($pages); $i++) {
      $kids[] = ($page_obj_start + ($i * 2)) . " 0 R";
    }
    $objects[] = "<< /Type /Pages /Kids [ " . implode(' ', $kids) . " ] /Count " . count($kids) . " >>";

    for ($i = 0; $i < count($pages); $i++) {
      $page_num = $page_obj_start + ($i * 2);
      $cont_num = $page_num + 1;
      $objects[] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 " . (3 + count($pages) * 2) . " 0 R >> >> /Contents {$cont_num} 0 R >>";
      $stream = $pages[$i];
      $objects[] = "<< /Length " . strlen($stream) . " >>\nstream\n{$stream}\nendstream";
    }

    $objects[] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>";

    for ($i = 0; $i < count($objects); $i++) {
      $offsets[$i + 1] = strlen($pdf);
      $pdf .= ($i + 1) . " 0 obj\n" . $objects[$i] . "\nendobj\n";
    }
    $xref_pos = strlen($pdf);

    $pdf .= "xref\n0 " . (count($objects) + 1) . "\n";
    $pdf .= "0000000000 65535 f \n";
    for ($i = 1; $i <= count($objects); $i++) {
      $pdf .= str_pad((string) $offsets[$i], 10, '0', STR_PAD_LEFT) . " 00000 n \n";
    }
    $pdf .= "trailer\n<< /Size " . (count($objects) + 1) . " /Root 1 0 R >>\n";
    $pdf .= "startxref\n{$xref_pos}\n%%EOF";

    return $pdf;
  }
}

/**
 * Handler: export (Excel/PDF)
 * URL: /wp-admin/admin-post.php?action=acme_export_credit_transactions&format=xls|pdf&_wpnonce=...
 */
add_action('admin_post_acme_export_credit_transactions', function () {

  if (!function_exists('acme_can_view_transactions') || !acme_can_view_transactions()) {
    wp_die('Sem permissão.');
  }

  if (!isset($_GET['_wpnonce']) || !wp_verify_nonce($_GET['_wpnonce'], 'acme_tx_export')) {
    wp_die('Nonce inválido.');
  }

  $format = isset($_GET['format']) ? sanitize_text_field((string) $_GET['format']) : '';
  if (!in_array($format, ['xls', 'pdf'], true)) {
    wp_die('Formato inválido.');
  }

  $viewer_id = get_current_user_id();

  // Busca “tudo” (com limite de segurança)
  $filters = acme_tx_read_filters();
  $limit = 5000;
  $data = acme_tx_fetch_rows($viewer_id, $filters, $limit, 0);
  $rows = (array) $data['rows'];

  $filename_base = 'credit-transactions-' . date('Ymd-His');

  if ($format === 'xls') {
    header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename_base . '.xls"');
    header('Pragma: no-cache');
    header('Expires: 0');

    echo "<html><head><meta charset=\"UTF-8\"></head><body>";
    echo "<table border=\"1\" cellpadding=\"5\" cellspacing=\"0\">";
    echo "<thead><tr>";
    echo "<th>ID</th><th>Data</th><th>Usuário</th><th>Email</th><th>Serviço</th><th>Tipo</th><th>Origem</th><th>Status</th><th>Créditos</th>";
    echo "</tr></thead><tbody>";

    foreach ($rows as $r) {
      $status_valor = ($r->status == 'success') ? 'Sucesso' : 'Falha';
      $status_tipo = ($r->type == 'debit') ? 'Debito' : 'Crédito';
      $origin = match ($r->origin) {
        'concession' => 'Concedido',
        'reserved'   => 'Estornado',
        'consumption'   => 'Consumido',
        default      => '',
      };
      echo "<tr>";
      echo "<td>" . (int) $r->id . "</td>";
      echo "<td>" . esc_html($r->created_at) . "</td>";
      echo "<td>" . esc_html($r->display_name) . "</td>";
      echo "<td>" . esc_html($r->user_email) . "</td>";
      echo "<td>" . esc_html($r->service_name ?: '—') . "</td>";
      echo "<td>" . $status_tipo . "</td>";
      echo "<td>" . $origin . "</td>";
      echo "<td>" . $status_valor . "</td>";
      echo "<td>" . (int) $r->credits . "</td>";
      echo "</tr>";
    }

    echo "</tbody></table></body></html>";
    exit;
  }

  // PDF bonito (Dompdf) — se disponível. Se não, fallback para PDF simples.
  $title = 'Relatório de transações (créditos)';

  $html = '<!doctype html><html><head><meta charset="UTF-8">';
  $html .= '<style>'
    . 'body{font-family:DejaVu Sans, sans-serif;font-size:10px;color:#111}'
    . 'h1{font-size:14px;margin:0 0 8px 0}'
    . '.meta{margin:0 0 10px 0;color:#444}'
    . 'table{width:100%;border-collapse:collapse}'
    . 'th,td{border:1px solid #ccc;padding:6px;vertical-align:top}'
    . 'th{background:#f3f4f6;font-weight:bold}'
    . '.num{text-align:right;white-space:nowrap}'
    . '</style></head><body>';
  $html .= '<h1>' . esc_html($title) . '</h1>';
  $html .= '<div class="meta">Gerado em: ' . esc_html(date_i18n('d/m/Y H:i')) . '</div>';
  $html .= '<table><thead><tr>'
    . '<th>ID</th>'
    . '<th>Data</th>'
    . '<th>Usuário</th>'
    . '<th>Email</th>'
    . '<th>Serviço</th>'
    . '<th>Tipo</th>'
    . '<th>Status</th>'
    . '<th class="num">Créditos</th>'
    . '</tr></thead><tbody>';

  foreach ($rows as $r) {
    $status_valor = ($r->status == 'success') ? 'Sucesso' : 'Falha';
    $status_tipo = ($r->type == 'debit') ? 'Debito' : 'Crédito';

    $html .= '<tr>'
      . '<td>' . (int) $r->id . '</td>'
      . '<td>' . esc_html($r->created_at) . '</td>'
      . '<td>' . esc_html($r->display_name) . '</td>'
      . '<td>' . esc_html($r->user_email) . '</td>'
      . '<td>' . esc_html($r->service_name ?: '—') . '</td>'
      . '<td>' . $status_tipo . '</td>'
      . '<td>' . $status_valor . '</td>'
      . '<td class="num">' . (int) $r->credits . '</td>'
      . '</tr>';
  }

  $html .= '</tbody></table></body></html>';

  if (function_exists('acme_pdf_is_available') && acme_pdf_is_available()) {
    acme_pdf_stream_html($html, $filename_base . '.pdf');
    exit;
  }

  // Fallback: PDF simples (texto)
  $out_lines = [];
  foreach ($rows as $r) {
    $out_lines[] = sprintf(
      '#%d | %s | %s (%s) | %s | %s | %s | %d',
      (int) $r->id,
      (string) $r->created_at,
      (string) $r->display_name,
      (string) $r->user_email,
      (string) ($r->service_name ?: '—'),
      (string) $r->type,
      (string) $r->status,
      (int) $r->credits
    );
  }

  $pdf = acme_tx_build_simple_pdf($title, $out_lines);
  header('Content-Type: application/pdf');
  header('Content-Disposition: attachment; filename="' . $filename_base . '.pdf"');
  header('Pragma: no-cache');
  header('Expires: 0');
  echo $pdf;
  exit;
});

/**
 * ============================================================
 * Render do relatório (com filtros + tabela)
 * ============================================================
 */
if (!function_exists('acme_render_transactions_table')) {
  function acme_render_transactions_table(string $context = 'front'): string
  {
    global $wpdb;

    $txT       = acme_table_credit_transactions();
    $usersT    = $wpdb->users;
    $servicesT = acme_table_services();

    // filtros (GET)
    $q          = isset($_GET['q']) ? trim((string) $_GET['q']) : '';
    $type       = isset($_GET['type']) ? sanitize_text_field((string) $_GET['type']) : '';
    $status     = isset($_GET['status']) ? sanitize_text_field((string) $_GET['status']) : '';
    $user_id    = isset($_GET['user_id']) ? (int) $_GET['user_id'] : 0;
    $service_id = isset($_GET['service_id']) ? (int) $_GET['service_id'] : 0;
    $date_from  = isset($_GET['from']) ? sanitize_text_field((string) $_GET['from']) : '';
    $date_to    = isset($_GET['to']) ? sanitize_text_field((string) $_GET['to']) : '';

    $where = [];
    $args  = [];

    $viewer_id = get_current_user_id();
    $scope     = acme_tx_scope($viewer_id);

    $is_admin_view  = ($scope['mode'] === 'admin');
    $is_master_view = ($scope['mode'] === 'child');
    $allowed_ids    = $is_admin_view ? [] : (array) $scope['allowed_ids'];

    // trava o SQL no escopo
    $where[] = $scope['sql'];
    $args    = array_merge($args, $scope['args']);

    // Se for master/sub, NÃO deixa forçar user_id fora do escopo
    if (!$is_admin_view && $user_id > 0 && !in_array($user_id, array_map('intval', $allowed_ids), true)) {
      $user_id = 0;
    }

    // Lista do dropdown de usuários (conforme perfil)
    if ($is_admin_view) {
      $users = get_users([
        'orderby' => 'display_name',
        'order'   => 'ASC',
        'number'  => 400,
      ]);
    } else {
      $users = get_users([
        'include' => array_map('intval', $allowed_ids),
        'orderby' => 'display_name',
        'order'   => 'ASC',
        'number'  => 400,
      ]);
    }

    // filtros adicionais
    if ($q !== '') {
      $where[] = "(u.display_name LIKE %s OR u.user_email LIKE %s OR u.user_login LIKE %s OR t.request_id LIKE %s)";
      $like = '%' . $wpdb->esc_like($q) . '%';
      array_push($args, $like, $like, $like, $like);
    }

    if (in_array($type, ['credit', 'debit', 'refund', 'adjust', 'attempt'], true)) {
      $where[] = "t.type = %s";
      $args[]  = $type;
    }

    if (in_array($status, ['pending', 'success', 'failed', 'canceled'], true)) {
      $where[] = "t.status = %s";
      $args[]  = $status;
    }

    if ($user_id > 0) {
      $where[] = "t.user_id = %d";
      $args[]  = $user_id;
    }

    if ($service_id > 0) {
      $where[] = "t.service_id = %d";
      $args[]  = $service_id;
    }

    if ($date_from) {
      $where[] = "t.created_at >= %s";
      $args[]  = $date_from . ' 00:00:00';
    }
    if ($date_to) {
      $where[] = "t.created_at <= %s";
      $args[]  = $date_to . ' 23:59:59';
    }

    $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    // paginação
    $per_page = 30;
    $page     = isset($_GET['paged']) ? max(1, (int) $_GET['paged']) : 1;
    $offset   = ($page - 1) * $per_page;

    $sqlCount = "
      SELECT COUNT(*)
      FROM {$txT} t
      INNER JOIN {$usersT} u ON u.ID = t.user_id
      LEFT JOIN {$servicesT} s ON s.id = t.service_id
      {$whereSql}
    ";
    $total = (int) $wpdb->get_var($wpdb->prepare($sqlCount, $args));

    $sql = "
      SELECT
        t.*,
        u.display_name, u.user_email,
        s.name AS service_name, s.slug AS service_slug
      FROM {$txT} t
      INNER JOIN {$usersT} u ON u.ID = t.user_id
      LEFT JOIN {$servicesT} s ON s.id = t.service_id
      {$whereSql}
      ORDER BY t.id DESC
      LIMIT %d OFFSET %d
    ";
    $rows = $wpdb->get_results($wpdb->prepare($sql, array_merge($args, [$per_page, $offset])));

    // serviços
    $services = $wpdb->get_results("SELECT id, name FROM {$servicesT} ORDER BY name ASC");

    // Link export mantendo filtros
    $export_args = $_GET;
    unset($export_args['paged']);
    $export_args['action']   = 'acme_export_credit_transactions';
    $export_args['_wpnonce'] = wp_create_nonce('acme_tx_export');

    $export_args['format'] = 'xls';
    $xls_url = add_query_arg($export_args, admin_url('admin-post.php'));

    // limpar filtros (para botão)
    $has_any_filter =
      ($q !== '') ||
      ($type !== '') ||
      ($status !== '') ||
      ($user_id > 0) ||
      ($service_id > 0) ||
      ($date_from !== '') ||
      ($date_to !== '');

    ob_start();
?>
    <div class="acme-panel acme-tx-panel">
      <div class="acme-panel-h">
        <div>
          <div class="acme-panel-title">Transações de Créditos</div>
          <div class="acme-panel-sub">Filtre e exporte o histórico (Excel) respeitando seu escopo.</div>
        </div>

        <div class="acme-actions">
          <a class="acme-btn" href="<?php echo esc_url(add_query_arg([], get_permalink())); ?>">Atualizar</a>
          <a class="acme-btn" href="<?php echo esc_url($xls_url); ?>" title="Baixar Excel">⬇ Baixar Relatório</a>
          <button type="submit"
            form="acme-tx-filter-form"
            class="acme-btn-icon"
            aria-label="Pesquisar">
            <svg viewBox="0 0 24 24" fill="none" aria-hidden="true">
              <path d="M10.5 18a7.5 7.5 0 1 1 0-15 7.5 7.5 0 0 1 0 15Z" stroke="currentColor" stroke-width="2" />
              <path d="M16.5 16.5 21 21" stroke="currentColor" stroke-width="2" stroke-linecap="round" />
            </svg>
          </button>


          <?php if ($has_any_filter): ?>
            <a class="acme-btn" href="<?php echo esc_url(remove_query_arg(['q', 'type', 'status', 'user_id', 'service_id', 'from', 'to', 'paged'])); ?>">
              Limpar filtros
            </a>
          <?php endif; ?>
        </div>
      </div>

      <div class="acme-panel-body">
        <form id="acme-tx-filter-form" method="get" class="acme-filter-grid"> <!--</form> <form method="get" class="acme-filter-grid">-->
          <?php if ($context === 'admin'): ?>
            <input type="hidden" name="page" value="acme_credit_transactions">
          <?php endif; ?>

          <!-- Linha 1: Buscar + Usuário -->
          <div class="acme-filter-row-2">
            <div class="acme-field">
              <label class="acme-muted">Buscar (nome/email/login)</label>
              <input class="acme-input" type="text" name="q" value="<?php echo esc_attr($q); ?>">
            </div>

            <div class="acme-field">
              <label class="acme-muted">Usuário</label>
              <select class="acme-input" name="user_id">
                <?php if (!empty($_GET['user_id'])): ?>
                  <?php
                  $uid_sel = (int) $_GET['user_id'];
                  $user_sel = get_user_by('id', $uid_sel);
                  ?>
                  <?php if ($user_sel): ?>
                    <option value="<?php echo (int) $uid_sel; ?>" selected>
                      <?php echo esc_html($user_sel->display_name . ' (#' . $uid_sel . ')'); ?>
                    </option>
                  <?php else: ?>
                    <option value="0">Todos</option>
                  <?php endif; ?>
                <?php else: ?>
                  <?php if ($is_admin_view || $is_master_view): ?>
                    <option value="0">Todos</option>
                  <?php endif; ?>
                  <?php foreach ($users as $u): ?>
                    <option value="<?php echo (int) $u->ID; ?>" <?php selected($user_id, (int) $u->ID); ?>>
                      <?php echo esc_html($u->display_name . ' (#' . $u->ID . ')'); ?>
                    </option>
                  <?php endforeach; ?>
                <?php endif; ?>
              </select>
            </div>
          </div>

          <!-- Linha 2: Serviço + De + Até -->
          <div class="acme-filter-row-4 ">
            <div class="acme-field">
              <label class="acme-muted">Serviço</label>
              <select class="acme-input" name="service_id">
                <option value="0">Todos</option>
                <?php foreach ((array) $services as $s): ?>
                  <option value="<?php echo (int) $s->id; ?>" <?php selected($service_id, (int) $s->id); ?>>
                    <?php echo esc_html($s->name); ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="acme-field">
              <label class="acme-muted">De</label>
              <input class="acme-input" type="date" name="from" value="<?php echo esc_attr($date_from); ?>">
            </div>

            <div class="acme-field">
              <label class="acme-muted">Até</label>
              <input class="acme-input" type="date" name="to" value="<?php echo esc_attr($date_to); ?>">
            </div>

          </div>


        </form>

        <p class="acme-muted acme-total">
          Total encontrado: <strong><?php echo (int) $total; ?></strong>
        </p>
      </div>

      <?php if (empty($rows)): ?>
        <div class="acme-empty">Nenhuma transação encontrada.</div>
    </div>
    <?php return ob_get_clean(); ?>
  <?php endif; ?>

  <div class="acme-table-wrap">
    <table class="acme-table">
      <thead>
        <tr>
          <th>Data</th>
          <th>Usuário</th>
          <th>Serviço</th>
          <th>Tipo</th>
          <th>Origem</th>
          <th>Status</th>
          <th style="text-align:center;">Créditos</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $r): ?>
          <tr>
            <td class="acme-muted"><?php echo esc_html(date('d/m/Y H:i:s', strtotime($r->created_at))); ?></td>

            <td>
              <strong><?php echo esc_html($r->display_name); ?></strong><br>
              <span class="acme-muted"><?php echo esc_html($r->user_email); ?></span>
            </td>

            <td><?php echo $r->service_name ? esc_html($r->service_name) : '—'; ?></td>

            <td class="acme-mono">
              <?php
              echo esc_html(
                $r->type === 'credit' ? 'Creditado'
                  : ($r->type === 'debit' ? 'Debitado'
                    : ($r->type === 'reversed' ? 'Estornado'
                      : $r->type
                    )
                  )
              );
              ?>
            </td>

            <td class="acme-mono">
              <?php
              echo esc_html(
                match ($r->origin) {
                  'concession'  => 'Concedido',
                  'reserved'    => 'Estornado',
                  'consumption' => 'Consumido',
                  ''            => '',
                  default       => $r->origin,
                }
              );
              ?>

              <?php #echo esc_html($r->origin); 
              ?>
            </td>

            <td class="acme-mono">
              <?php echo esc_html($r->status === 'success' ? 'Sucesso' : ($r->status === 'failed' ? 'Falha' : $r->status)); ?>
            </td>

            <td style="text-align:center;font-weight:900;"><?php echo (int) $r->credits; ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <?php
    $total_pages = (int) ceil($total / $per_page);
    if ($total_pages > 1):
      $base = remove_query_arg('paged');
  ?>
    <div class="acme-pagination">
      <?php for ($p = 1; $p <= $total_pages; $p++):
        $url = add_query_arg('paged', $p, $base);
        $active = ($p === $page);
      ?>
        <a href="<?php echo esc_url($url); ?>" class="acme-btn <?php echo $active ? 'is-active' : ''; ?>">
          <?php echo (int) $p; ?>
        </a>
      <?php endfor; ?>
    </div>
  <?php endif; ?>

  </div>
<?php
    return ob_get_clean();
  }
}
