<?php
if (!defined('ABSPATH')) {
  exit;
}

//die('SHORTCODE FILE LOADED');


/**
 * ============================================================
 * Helpers (baixa complexidade / baixo risco)
 * ============================================================
 *
 * Motivação:
 * - Evitar usar o parâmetro GET "paged" diretamente nos shortcodes,
 *   pois ele pode conflitar com paginações/loops do próprio WordPress.
 * - O módulo de transações (acme_render_transactions_table) hoje lê "paged".
 *
 * Problema atual (clássico):
 * - Em muitos casos o paginate_links() gera URL do tipo /page/2/ (pretty permalinks).
 * - Nessa situação NÃO existe $_GET['paged']; a página vem via query_var do WP (get_query_var('paged')).
 * - Resultado: URL muda, mas o renderer lê $_GET e fica preso na página 1.
 *
 * Solução:
 * - Normaliza paginação a partir de:
 *   1) $_GET['paged'] (legado)
 *   2) $_GET['acme_page'] (recomendado)
 *   3) get_query_var('paged') / get_query_var('page') (permalinks /page/2/)
 * - Espelha o valor final em:
 *   - $_GET['paged'] e $_REQUEST['paged'] (compatibilidade com renderer antigo)
 *   - query vars do WP ($wp_query->set)
 *   - global $paged (compatibilidade com código legado)
 *
 * Resultado:
 * - Você pode usar ?acme_page=2 (recomendado)
 * - Continua aceitando ?paged=2 (legado)
 * - Funciona também com /page/2/ (permalinks)
 */
if (!function_exists('acme_normalize_pagination_param')) {
  function acme_normalize_pagination_param(string $preferred = 'acme_page', string $legacy = 'paged'): void
  {
    // --------------------------------------------------------
    // 0) Descobre o "melhor" valor de página disponível
    // --------------------------------------------------------
    $p = 0;

    // (A) Se já veio no GET legado, respeita
    if (isset($_GET[$legacy]) && (int) $_GET[$legacy] > 0) {
      $p = (int) $_GET[$legacy];
    }

    // (B) Se não veio legado, mas veio o recomendado, usa ele
    if ($p <= 0 && isset($_GET[$preferred]) && (int) $_GET[$preferred] > 0) {
      $p = (int) $_GET[$preferred];
    }

    // (C) Se não veio nada em GET, tenta pegar do WP (permalinks /page/2/)
    //     - 'paged' é o padrão de paginação
    //     - 'page' pode aparecer em páginas estáticas / alguns temas
    if ($p <= 0) {
      $qv_paged = (int) get_query_var($legacy);
      if ($qv_paged > 0) {
        $p = $qv_paged;
      }
    }

    if ($p <= 0) {
      $qv_page = (int) get_query_var('page');
      if ($qv_page > 0) {
        $p = $qv_page;
      }
    }

    // Sem paginação informada: não força nada (renderer assume 1)
    if ($p <= 0) {
      return;
    }

    // Normaliza mínimo
    $p = max(1, $p);

    // --------------------------------------------------------
    // 1) Espelha em $_GET/$_REQUEST (compatibilidade com renderer antigo)
    // --------------------------------------------------------
    $_GET[$legacy] = $p;
    $_REQUEST[$legacy] = $p;

    // Mantém coerência também no param recomendado (útil para links e debug)
    $_GET[$preferred] = $p;
    $_REQUEST[$preferred] = $p;

    // --------------------------------------------------------
    // 2) Espelha em query_vars do WP (compatibilidade com paginate_links e temas)
    // --------------------------------------------------------
    if (isset($GLOBALS['wp_query']) && is_object($GLOBALS['wp_query'])) {
      // 'paged' é o query_var mais usado
      $GLOBALS['wp_query']->set($legacy, $p);

      // 'page' aparece em alguns cenários (página estática / shortcodes)
      $GLOBALS['wp_query']->set('page', $p);
    }

    // --------------------------------------------------------
    // 3) Espelha no global $paged (muitos códigos antigos usam isso)
    // --------------------------------------------------------
    $GLOBALS['paged'] = $p;
  }
}



/**
 * Shortcode: [acme_credit_balance]
 * Mostra "Seus créditos disponíveis" conforme usuário logado:
 * - Admin: total geral (todos os lotes ativos do sistema)
 * - Master (child): total dele + netos dele (depth=2)
 * - Sub-Login (grandchild): total dele
 */
add_shortcode('acme_credit_balance', function ($atts) {

  if (!is_user_logged_in()) {
    return '<p>Você precisa estar logado.</p>';
  }

  $uid = get_current_user_id();
  $me = wp_get_current_user();

  $is_admin = user_can($uid, 'administrator');
  $is_child = in_array('child', (array) $me->roles, true);
  $is_grand = in_array('grandchild', (array) $me->roles, true);

  // Se não for admin/child/grandchild, por segurança, mostra só dele
  if (!$is_admin && !$is_child && !$is_grand) {
    $is_grand = true;
  }

  global $wpdb;

  // Tabelas
  $lotsT = function_exists('acme_table_credit_lots') ? acme_table_credit_lots() : ($wpdb->prefix . 'credit_lots');
  $linksT = function_exists('acme_table_links') ? acme_table_links() : ($wpdb->prefix . 'account_links');

  $now_mysql = current_time('mysql');

  // Monta lista de user_ids que entram na soma
  $user_ids = [];

  if ($is_admin) {
    // Admin: todos (não precisa listar IDs; soma direto na query)
    $user_ids = [];
  } elseif ($is_child) {
    // Master: ele + netos vinculados a ele (depth=2)
    $user_ids[] = (int) $uid;

    $grand_ids = $wpdb->get_col($wpdb->prepare(
      "SELECT child_user_id
         FROM {$linksT}
        WHERE parent_user_id = %d
          AND depth = 2",
      $uid
    ));

    foreach ((array) $grand_ids as $gid) {
      $user_ids[] = (int) $gid;
    }

    $user_ids = array_values(array_unique(array_filter($user_ids)));
  } else {
    // Sub-Login: só ele
    $user_ids = [(int) $uid];
  }

  // Calcula total
  if ($is_admin) {
    $total = (int) $wpdb->get_var($wpdb->prepare(
      "SELECT COALESCE(SUM(GREATEST(credits_total - credits_used, 0)), 0)
         FROM {$lotsT}
        WHERE (expires_at IS NULL OR expires_at >= %s)",
      $now_mysql
    ));
  } else {
    if (empty($user_ids)) {
      $total = 0;
    } else {
      $placeholders = implode(',', array_fill(0, count($user_ids), '%d'));

      // IMPORTANTE: no prepare, os %d vêm antes do %s, então passamos ids e por último a data
      $sql = $wpdb->prepare(
        "SELECT COALESCE(SUM(GREATEST(credits_total - credits_used, 0)), 0)
           FROM {$lotsT}
          WHERE owner_user_id IN ($placeholders)
            AND (expires_at IS NULL OR expires_at >= %s)",
        array_merge($user_ids, [$now_mysql])
      );

      $total = (int) $wpdb->get_var($sql);
    }
  }

  // UI simples (apenas o que você pediu)
  ob_start(); ?>

  <div style="opacity:.75;color:#fff">Créditos disponíveis: <?php echo (int) $total; ?></div>

<?php
  return ob_get_clean();
});




add_shortcode('teste', function () {

  if (!is_user_logged_in()) {
    return 'Faça login primeiro';
  }

  if (!function_exists('acme_service_clt')) {
    return 'Provider CLT não carregado';
  }

  $result = acme_service_clt(get_current_user_id(), '11111111111');

  return '<pre>' . print_r($result, true) . '</pre>';
});


add_shortcode('acme_clt_form', function () {

  if (!is_user_logged_in()) {
    return '<div style="padding:12px;border:1px solid #f2c;border-radius:10px;">Faça login para consultar.</div>';
  }

  ob_start(); ?>

  <style>
    /* Escopo só do card */
    #acme-clt-card {
      max-width: 100%;
      margin: 0 auto;
      border: 2px solid rgba(20, 55, 120, .25);
      border-radius: 14px;
      background: #fff;
      padding: 22px;
      box-shadow: 0 8px 22px rgba(0, 0, 0, .06);
      font-family: system-ui, -apple-system, Segoe UI, Roboto, Arial;
    }

    #acme-clt-head {
      display: flex;
      gap: 14px;
      align-items: flex-start;
      margin-bottom: 12px;
    }

    #acme-clt-icon {
      width: 52px;
      height: 52px;
      border-radius: 12px;
      background: rgba(22, 74, 160, .08);
      display: flex;
      align-items: center;
      justify-content: center;
      flex: 0 0 52px;
    }

    #acme-clt-title {
      margin: 0;
      font-size: 22px;
      font-weight: 800;
      color: #1e3a8a;
      line-height: 1.2;
    }

    #acme-clt-sub {
      margin: 6px 0 0;
      color: #6b7280;
      font-size: 14px;
      line-height: 1.35;
    }

    #acme-clt-form {
      margin-top: 14px;
      display: flex;
      gap: 10px;
      flex-wrap: wrap;
      align-items: center;
    }

    #acme-cpf {
      flex: 1 1 260px;
      min-width: 220px;
      padding: 12px 14px;
      border: 1px solid #e5e7eb;
      border-radius: 12px;
      font-size: 14px;
      outline: none;
    }

    #acme-cpf:focus {
      border-color: rgba(30, 58, 138, .55);
      box-shadow: 0 0 0 4px rgba(30, 58, 138, .12);
    }

    #acme-btn {
      flex: 0 0 auto;
      padding: 12px 18px;
      border: 0;
      border-radius: 12px;
      cursor: pointer;
      font-weight: 800;
      font-size: 14px;
      color: #fff;
      background: #1e3a8a;
      /* azul do modelo */
      box-shadow: 0 10px 18px rgba(30, 58, 138, .18);
      transition: transform .05s ease, opacity .15s ease;
    }

    #acme-btn:active {
      transform: translateY(1px);
    }

    #acme-btn:disabled {
      opacity: .6;
      cursor: not-allowed;
    }

    #acme-clt-result {
      margin-top: 14px;
    }

    /* Mensagens */
    .acme-msg {
      padding: 12px 14px;
      border-radius: 12px;
      border: 1px solid #e5e7eb;
      background: #f9fafb;
      font-size: 13px;
      color: #111827;
    }

    .acme-msg-err {
      border-color: rgba(220, 38, 38, .35);
      background: rgba(220, 38, 38, .06);
    }

    .acme-msg-ok {
      border-color: rgba(22, 163, 74, .35);
      background: rgba(22, 163, 74, .06);
    }

    /* Mobile */
    @media (max-width: 520px) {
      #acme-btn {
        width: 100%;
      }

      #acme-cpf {
        width: 100%;
      }
    }
  </style>

  <div id="acme-clt-card">
    <div id="acme-clt-head">
      <div id="acme-clt-icon" aria-hidden="true">
        <!-- Ícone simples (SVG inline) -->
        <svg width="26" height="26" viewBox="0 0 24 24" fill="none">
          <path d="M7 21h10a2 2 0 0 0 2-2V8l-4-4H7a2 2 0 0 0-2 2v13a2 2 0 0 0 2 2Z" stroke="#1e3a8a" stroke-width="2" />
          <path d="M15 4v4h4" stroke="#1e3a8a" stroke-width="2" />
          <path d="M8 13h8M8 17h6" stroke="#1e3a8a" stroke-width="2" stroke-linecap="round" />
        </svg>
      </div>

      <div>
        <h3 id="acme-clt-title">Consulta CLT</h3>
        <p id="acme-clt-sub">
          Consulte vínculos empregatícios atualizados usando o CPF.
        </p>
      </div>
    </div>

    <div id="acme-clt-form">
      <input id="acme-cpf" type="text" inputmode="numeric" placeholder="Digite o CPF (somente números)" maxlength="14" />
      <button id="acme-btn" type="button">Consultar agora o CPF</button>
    </div>

    <div id="acme-clt-result"></div>
  </div>

<?php
  return ob_get_clean();
});



/**
 * ============================================================
 * SHORTCODE FRONT (Elementor): [acme_credit_transactions]
 * ============================================================
 *
 * Ajuste aplicado:
 * - Normaliza paginação aceitando:
 *   - ?acme_page=N (recomendado)
 *   - ?paged=N (legado)
 *   - /page/N/ (permalinks / paginate_links)
 *
 * Importante:
 * - Não alteramos o renderer aqui (baixo risco).
 * - Apenas garantimos que o renderer sempre "enxergue" a página atual,
 *   mesmo quando ela vem via query_var (permalink bonito).
 */
if (!function_exists('acme_shortcode_credit_transactions')) {
  add_shortcode('acme_credit_transactions', function ($atts = []) {

    // ---------
    // 1) Segurança / RBAC
    // ---------
    if (!function_exists('acme_can_view_transactions') || !acme_can_view_transactions()) {
      // Retorna vazio para não "denunciar" que existe relatório
      return '<p></p>';
    }

    // ---------
    // 2) Adapter de paginação (robusto)
    // ---------
    // Use: ?acme_page=2 (recomendado) | aceita ?paged=2 | aceita /page/2/
    acme_normalize_pagination_param('acme_page', 'paged');

    // ---------
    // 3) Render (mantém tudo que já existe no módulo)
    // ---------
    return acme_render_transactions_table('front');
  });
}



## Assinaturas
add_shortcode('acme_user_subscriptions', 'acme_shortcode_user_subscriptions');

function acme_shortcode_user_subscriptions()
{
  if (!is_user_logged_in())
    return '<p>Você precisa estar logado.</p>';

  $is_admin = current_user_can('manage_options');

  // Admin usa user_id via GET; usuário comum vê o próprio
  $target_user_id = 0;
  if ($is_admin) {
    $target_user_id = isset($_GET['user_id']) ? (int) $_GET['user_id'] : 0;
    if ($target_user_id <= 0) {
      return '<p>Usuário não identificado.</code></p>';
    }
  } elseif (isset($_GET['user_id'])) {
    $target_user_id = isset($_GET['user_id']) ? (int) $_GET['user_id'] : 0;
  } else {
    $target_user_id = get_current_user_id();
  }

  $u = get_user_by('id', $target_user_id);
  if (!$u)
    return '<p>Usuário não encontrado.</p>';

  // Somente FILHOS recebem assinatura (regra do projeto)
  $is_child = in_array('child', (array) $u->roles, true);
  if (!$is_child && $is_admin) {
    // Admin pode consultar mesmo assim, mas alerta
  }

  global $wpdb;

  // tabelas
  $contractsT = function_exists('acme_table_credit_contracts') ? acme_table_credit_contracts() : ($wpdb->prefix . 'credit_contracts');
  $servicesT = function_exists('acme_table_services') ? acme_table_services() : ($wpdb->prefix . 'services');

  // filtro opcional de serviço: ?service_id=1
  $service_id = isset($_GET['service_id']) ? (int) $_GET['service_id'] : 0;

  $where = "c.child_user_id = %d";
  $params = [$target_user_id];

  if ($service_id > 0) {
    $where .= " AND c.service_id = %d";
    $params[] = $service_id;
  }

  // paginação simples
  $per_page = 5;//20;
  $page = isset($_GET['pg']) ? max(1, (int) $_GET['pg']) : 1;
  $offset = ($page - 1) * $per_page;

  // total
  $count_sql = "SELECT COUNT(*) FROM {$contractsT} c WHERE {$where}";
  $total_rows = (int) $wpdb->get_var($wpdb->prepare($count_sql, ...$params));
  $total_pages = max(1, (int) ceil($total_rows / $per_page));

  // lista
  $list_sql =
    "SELECT c.id, c.child_user_id, c.service_id, c.credits_total, c.credits_used, c.valid_until, c.created_at,
              s.name AS service_name, s.slug AS service_slug
       FROM {$contractsT} c
       LEFT JOIN {$servicesT} s ON s.id = c.service_id
       WHERE {$where}
       ORDER BY c.valid_until DESC, c.id DESC
       LIMIT %d OFFSET %d";

  $rows = $wpdb->get_results($wpdb->prepare($list_sql, ...array_merge($params, [$per_page, $offset])));

  $now_ts = current_time('timestamp');

  // helpers
  $calc_status = function ($valid_until, $total, $used) use ($now_ts) {
    $exp = strtotime($valid_until);
    $avail = max(0, (int) $total - (int) $used);

    if ($exp && $now_ts > $exp)
      return 'VENCIDO';
    if ($avail <= 0)
      return 'EXCEDIDO';
    return 'ATIVO';
  };

  $badge = function ($status) {
    $style = 'display:inline-block;padding:4px 10px;border-radius:999px;font-weight:700;';
    if ($status === 'ATIVO')
      return '<span style="' . $style . 'background:#e8fff0;color:#0a7a2f">ATIVO</span>';
    if ($status === 'VENCIDO')
      return '<span style="' . $style . 'background:#fff0f0;color:#b00020">VENCIDO</span>';
    return '<span style="' . $style . 'background:#fff7e6;color:#8a5a00">EXCEDIDO</span>';
  };

  // resumo
  $sum_total = 0;
  $sum_used = 0;
  $sum_current_active = 0;

  foreach ((array) $rows as $r) {
    $sum_total += (int) $r->credits_total;
    $sum_used += (int) $r->credits_used;

    $status = $calc_status($r->valid_until, $r->credits_total, $r->credits_used);
    if ($status === 'ATIVO') {
      $sum_current_active += max(0, (int) $r->credits_total - (int) $r->credits_used);
    }
  }

  // construir URL base pra paginação mantendo user_id/service_id
  $base_url = remove_query_arg(['pg'], esc_url_raw(add_query_arg([])));

  ob_start(); ?>

  <div style="max-width:1100px;margin:0 auto">
    <div style="border:1px solid #e5e7eb;border-radius:12px;padding:14px;margin:12px 0;background:#fff">
      <div style="display:flex;justify-content:space-between;align-items:flex-end;gap:16px;flex-wrap:wrap">
        <div>
          <div style="opacity:.75">Assinaturas do usuário</div>
          <div style="font-weight:800">
            <?php echo esc_html($u->display_name); ?> <span
              style="opacity:.6;font-weight:600">(#<?php echo (int) $u->ID; ?>)</span>
          </div>
          <?php if (!$is_child): ?>
            <div style="margin-top:6px;color:#8a5a00">Atenção: Assinaturas
              normalmente são apenas para master.</div>
          <?php endif; ?>
        </div>

        <?php if ($is_child || $is_admin): ?>
          <div style="text-align:right">
            <div style="opacity:.75">Resumo</div>
            <div style="font-weight:700">
              Total: <?php echo (int) $sum_total; ?> • Usado: <?php echo (int) $sum_used; ?>
              <?php #echo (int) $sum_current_active; 
              ?>
            </div>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <div style="border:1px solid #e5e7eb;border-radius:12px;overflow:hidden;background:#fff">
      <div
        style="padding:12px 14px;border-bottom:1px solid #e5e7eb;display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap">
        <div style="font-weight:800">Créditos por Assinaturas</div>
        <div style="opacity:.75">
          <?php echo (int) $total_rows; ?> contrato(s)
        </div>
      </div>

      <div style="overflow:auto">
        <table style="width:100%;border-collapse:collapse;min-width:860px">
          <thead>
            <tr style="background:#f9fafb;text-align:left">
              <th style="padding:10px;border-bottom:1px solid #e5e7eb">Nº contrato</th>
              <th style="padding:10px;border-bottom:1px solid #e5e7eb">Serviço</th>
              <th style="padding:10px;border-bottom:1px solid #e5e7eb">Vencimento</th>
              <th style="padding:10px;border-bottom:1px solid #e5e7eb">Créditos totais</th>
              <th style="padding:10px;border-bottom:1px solid #e5e7eb">Créditos disponíveis</th>
              <th style="padding:10px;border-bottom:1px solid #e5e7eb">Créditos usados</th>
              <th style="padding:10px;border-bottom:1px solid #e5e7eb">Status</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($rows)): ?>
              <tr>
                <td colspan="6" style="padding:14px">Nenhuma assinatura encontrada.</td>
              </tr>
            <?php else: ?>
              <?php foreach ($rows as $r):
                $total = (int) $r->credits_total;
                $used = (int) $r->credits_used;
                $current = max(0, $total - $used);
                $status = $calc_status($r->valid_until, $total, $used);
                $svc = $r->service_name ?: ('Serviço #' . (int) $r->service_id);
                $venc = $r->valid_until ? date_i18n('d/m/Y H:i', strtotime($r->valid_until)) : '-';
              ?>
                <tr>
                  <td style="padding:10px;border-bottom:1px solid #f0f2f5;font-weight:700">#<?php echo (int) $r->id; ?></td>
                  <td style="padding:10px;border-bottom:1px solid #f0f2f5"><?php echo esc_html($svc); ?></td>
                  <td style="padding:10px;border-bottom:1px solid #f0f2f5"><?php echo esc_html($venc); ?></td>
                  <td style="padding:10px;border-bottom:1px solid #f0f2f5"><?php echo (int) $total; ?></td>
                  <td style="padding:10px;border-bottom:1px solid #f0f2f5"><?php echo (int) $current; ?></td>
                  <td style="padding:10px;border-bottom:1px solid #f0f2f5"><?php echo (int) $total - (int) $current; ?></td>
                  <td style="padding:10px;border-bottom:1px solid #f0f2f5"><?php echo $badge($status); ?></td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>

      <?php if ($total_pages > 1): ?>
        <div style="padding:12px 14px;display:flex;justify-content:flex-end;gap:8px;align-items:center">
          <?php
          $prev = max(1, $page - 1);
          $next = min($total_pages, $page + 1);
          ?>
          <a href="<?php echo esc_url(add_query_arg('pg', $prev, $base_url)); ?>"
            style="text-decoration:none;padding:8px 10px;border:1px solid #e5e7eb;border-radius:10px">←</a>
          <div style="opacity:.8">Página <?php echo (int) $page; ?> de <?php echo (int) $total_pages; ?></div>
          <a href="<?php echo esc_url(add_query_arg('pg', $next, $base_url)); ?>"
            style="text-decoration:none;padding:8px 10px;border:1px solid #e5e7eb;border-radius:10px">→</a>
        </div>
      <?php endif; ?>
    </div>
  </div>

<?php
  return ob_get_clean();
}


##  Extrato
add_shortcode('acme_credits_snapshot', function () {

  if (!is_user_logged_in())
    return '';

  global $wpdb;

  // Tabelas reais do seu banco
  $txT = 'wp_credit_transactions'; // Log de transações
  $servicesT = 'wp_services';            // Tipos de serviço
  $lotsT = 'wp_credit_lots'; #Histórico de uso
  $usersT = $wpdb->users;

  // Usuário logado
  $viewer_id = get_current_user_id();

  /**
   * Usuário alvo:
   * - Se vier da URL (view/edit), usa o user_id
   * - Caso contrário, usa o usuário logado
   */
  $target_id = isset($_GET['user_id'])
    ? (int) $_GET['user_id']
    : $viewer_id;

  // Flag de admin (só influencia queries)
  $is_admin_view = current_user_can('manage_options');

  // Dados do usuário alvo
  $target_user = get_user_by('id', $target_id);
  $target_name = $target_user ? $target_user->display_name : ('#' . $target_id);

  $snapshot = $wpdb->get_results($wpdb->prepare("
    SELECT
        s.id   AS service_id,
        s.name AS service_name,
        SUM(l.credits_total - l.credits_used) AS saldo
    FROM {$lotsT} l
    INNER JOIN {$servicesT} s ON s.id = l.service_id
    WHERE l.owner_user_id = %d
      AND (l.expires_at IS NULL OR l.expires_at >= NOW())
    GROUP BY s.id, s.name
    ORDER BY s.name ASC
", $target_id));


  // 2) Histórico (linhas simples do usuário)
  $history_limit = 20;
  $history = $wpdb->get_results($wpdb->prepare("
    SELECT
        t.id,
        t.created_at,
        t.type,
        t.credits,
        t.user_id AS tx_user_id,
        t.notes,
        u.display_name AS tx_user_name,
        u.user_email   AS tx_user_email,
        COALESCE(s.name, t.service_name, t.service_slug, '—') AS service_name
    FROM {$txT} t
    LEFT JOIN {$usersT} u ON u.ID = t.user_id
    LEFT JOIN {$servicesT} s ON s.id = t.service_id
    WHERE (%d = 1 OR t.user_id = %d)
      AND t.status = 'success'
      AND t.type IN ('credit','debit')
    ORDER BY t.id DESC
    LIMIT %d
", $is_admin_view ? 1 : 0, $target_id, $history_limit));


  ob_start();
?>
  <style>
    .acme-wrap {
      font-family: system-ui, -apple-system, BlinkMacSystemFont;
      max-width: auto;
    }

    /* ===== Snapshot ===== */
    .acme-snapshot {
      background: #0b1220;
      padding: 16px 20px;
      border-radius: 12px;
    }

    .acme-snapshot-title {
      color: #9ca3af;

      margin-bottom: 12px;
      text-transform: uppercase;
      letter-spacing: .08em;
      display: flex;
      justify-content: space-between;
      gap: 12px;
      flex-wrap: wrap;
    }

    .acme-snapshot-title strong {
      color: #e5e7eb;
      font-weight: 600;
    }

    .acme-snapshot-row {
      display: flex;
      justify-content: space-between;
      align-items: center;
      padding: 8px 0;
      border-bottom: 1px solid rgba(255, 255, 255, .06);

      gap: 10px;
    }

    .acme-snapshot-row:last-child {
      border-bottom: none;
    }

    .acme-snapshot-name {
      color: #e5e7eb;
      font-weight: 600;
    }

    .acme-snapshot-saldo {
      font-weight: 700;
      white-space: nowrap;
    }

    .acme-pos {
      color: #22c55e;
    }

    .acme-neg {
      color: #ef4444;
    }

    /* ===== Histórico ===== */
    .acme-history {
      margin-top: 14px;
      border: 1px solid #e5e7eb;
      border-radius: 12px;
      overflow: hidden;
      background: #fff;
    }

    .acme-history-head {
      padding: 12px 14px;
      border-bottom: 1px solid #eef2f7;
      display: flex;
      justify-content: space-between;
      align-items: baseline;
      gap: 10px;
      flex-wrap: wrap;
    }

    .acme-history-title {
      font-weight: 700;
      color: #111827;

    }

    .acme-history-sub {

      opacity: .75;
    }

    .acme-table {
      width: 100%;
      border-collapse: collapse;

    }

    .acme-table th {
      text-align: left;
      padding: 10px 12px;
      border-bottom: 1px solid #eef2f7;
      background: #fafafa;
      font-weight: 600;
      color: #111827;
      white-space: nowrap;
    }

    .acme-table td {
      padding: 10px 12px;
      border-bottom: 1px solid #f1f5f9;
      vertical-align: top;
    }

    .acme-right {
      text-align: right;
      white-space: nowrap;
    }

    .acme-delta-pos {
      color: #16a34a;
      font-weight: 700;
    }

    .acme-delta-neg {
      color: #dc2626;
      font-weight: 700;
    }

    .acme-muted {
      opacity: .75;
    }

    .acme-empty {
      padding: 12px 14px;
    }
  </style>

  <div class="acme-wrap">

    <div class="acme-snapshot">
      <div class="acme-snapshot-title">
        <!--span>Saldo por serviço</span>-->
        <?php if ($is_admin_view): ?>
          <span>Movimentações</span><!--Histórico de uso-->

        <?php else: ?>

          <span>Extrato</span>

        <?php endif; ?>
        <span class="acme-muted">Usuário: <strong><?php echo esc_html($target_name); ?></strong></span>
      </div>

      <?php if (empty($snapshot)): ?>
        <!--<div class="acme-muted">Nenhum serviço encontrado para este usuário.</div>-->
      <?php else: ?>
        <?php foreach ($snapshot as $r):
          $saldo = (int) $r->saldo;
          $clsSaldo = ($saldo < 0) ? 'acme-neg' : 'acme-pos';
        ?>
          <div class="acme-snapshot-row">
            <span class="acme-snapshot-name"><?php echo "Extrato de Consumo"; //esc_html($r->service_name); 
                                              ?></span>

            <span class="acme-snapshot-saldo <?php echo esc_attr($clsSaldo); ?>">
              <?php echo number_format($saldo, 0, ',', '.'); ?>
            </span>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>

    <div class="acme-history">
      <div class="acme-history-head">
        <div class="acme-history-title">Histórico</div>
        <div class="acme-history-sub">Últimas <?php echo (int) $history_limit; ?> movimentações (success)</div>
        <div class=""><?php echo do_shortcode('[acme_export_button report="credits_extract_last20"]'); ?></div>
      </div>

      <?php if (empty($history)): ?>
        <div class="acme-empty">Nenhuma movimentação encontrada.</div>
      <?php else: ?>
        <div style="overflow:auto">
          <table class="acme-table">
            <thead>
              <tr>
                <th>Usuário</th>
                <th>Serviço</th>
                <th>Descrição</th>
                <th class="acme-right">Saldo</th>
              </tr>
            </thead>


            <tbody>
              <?php foreach ($history as $h):

                $delta = ($h->type === 'debit')
                  ? -abs((int) $h->credits)
                  : abs((int) $h->credits);

                $cls = ($delta < 0) ? 'acme-delta-neg' : 'acme-delta-pos';
                $txt = ($delta >= 0 ? '+' : '') . number_format($delta, 0, ',', '.');

                // Se existe user_id na URL, SEMPRE é o usuário alvo
                $show_target_user = isset($_GET['user_id']);

              ?>
                <tr>
                  <td>
                    <?php if ($is_admin_view && !$show_target_user): ?>
                      <!-- Admin em visão global -->
                      <strong>
                        <?php echo esc_html($h->tx_user_name ?: ('#' . (int) $h->tx_user_id)); ?>
                      </strong><br>
                      <span class="acme-muted">#<?php echo (int) $h->tx_user_id; ?></span>
                    <?php else: ?>
                      <!-- View/Edit ou usuário comum -->
                      <strong><?php echo esc_html($target_name); ?></strong><br>
                      <span class="acme-muted">#<?php echo (int) $target_id; ?></span>
                    <?php endif; ?>
                  </td>

                  <td><?php echo esc_html($h->service_name); ?></td>

                  <td><?php echo esc_html($h->notes); ?></td>

                  <td class="acme-right <?php echo esc_attr($cls); ?>">
                    <?php echo esc_html($txt); ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>



          </table>
        </div>
      <?php endif; ?>
    </div>

  </div>
<?php

  return ob_get_clean();
});



##   Assinaturas usuario atual/meu perfil
add_shortcode('acme_user_atual', 'acme_shortcode_user_subscriptions2');

function acme_shortcode_user_subscriptions2()
{
  if (!is_user_logged_in())
    return '<p>Você precisa estar logado.</p>';

  $is_admin = current_user_can('manage_options');

  // ✅ NOVO: por padrão, sempre usa o usuário logado
  $target_user_id = get_current_user_id();

  // (Opcional) Admin pode consultar outro usuário via ?user_id=123
  if ($is_admin && isset($_GET['user_id']) && (int) $_GET['user_id'] > 0) {
    $target_user_id = (int) $_GET['user_id'];
  }

  $u = get_user_by('id', $target_user_id);
  if (!$u)
    return '<p>Usuário não encontrado.</p>';

  // Somente FILHOS recebem assinatura (regra do projeto)
  $is_child = in_array('child', (array) $u->roles, true);
  if (!$is_child && $is_admin) {
    // Admin pode consultar mesmo assim, mas alerta
  }

  global $wpdb;

  // tabelas
  $contractsT = function_exists('acme_table_credit_contracts') ? acme_table_credit_contracts() : ($wpdb->prefix . 'credit_contracts');
  $servicesT = function_exists('acme_table_services') ? acme_table_services() : ($wpdb->prefix . 'services');

  // filtro opcional de serviço: ?service_id=1
  $service_id = isset($_GET['service_id']) ? (int) $_GET['service_id'] : 0;

  $where = "c.child_user_id = %d";
  $params = [$target_user_id];

  if ($service_id > 0) {
    $where .= " AND c.service_id = %d";
    $params[] = $service_id;
  }

  // paginação simples
  $per_page = 20;
  $page = isset($_GET['pg']) ? max(1, (int) $_GET['pg']) : 1;
  $offset = ($page - 1) * $per_page;

  // total
  $count_sql = "SELECT COUNT(*) FROM {$contractsT} c WHERE {$where}";
  $total_rows = (int) $wpdb->get_var($wpdb->prepare($count_sql, ...$params));
  $total_pages = max(1, (int) ceil($total_rows / $per_page));

  // lista
  $list_sql =
    "SELECT c.id, c.child_user_id, c.service_id, c.credits_total, c.credits_used, c.valid_until, c.created_at,
              s.name AS service_name, s.slug AS service_slug
       FROM {$contractsT} c
       LEFT JOIN {$servicesT} s ON s.id = c.service_id
       WHERE {$where}
       ORDER BY c.valid_until DESC, c.id DESC
       LIMIT %d OFFSET %d";

  $rows = $wpdb->get_results($wpdb->prepare($list_sql, ...array_merge($params, [$per_page, $offset])));

  $now_ts = current_time('timestamp');

  // helpers
  $calc_status = function ($valid_until, $total, $used) use ($now_ts) {
    $exp = strtotime($valid_until);
    $avail = max(0, (int) $total - (int) $used);

    if ($exp && $now_ts > $exp)
      return 'VENCIDO';
    if ($avail <= 0)
      return 'EXCEDIDO';
    return 'ATIVO';
  };

  $badge = function ($status) {
    $style = 'display:inline-block;padding:4px 10px;border-radius:999px;font-weight:700;';
    if ($status === 'ATIVO')
      return '<span style="' . $style . 'background:#e8fff0;color:#0a7a2f">ATIVO</span>';
    if ($status === 'VENCIDO')
      return '<span style="' . $style . 'background:#fff0f0;color:#b00020">VENCIDO</span>';
    return '<span style="' . $style . 'background:#fff7e6;color:#8a5a00">EXCEDIDO</span>';
  };

  // resumo
  $sum_total = 0;
  $sum_used = 0;
  $sum_current_active = 0;

  foreach ((array) $rows as $r) {
    $sum_total += (int) $r->credits_total;
    $sum_used += (int) $r->credits_used;

    $status = $calc_status($r->valid_until, $r->credits_total, $r->credits_used);
    if ($status === 'ATIVO') {
      $sum_current_active += max(0, (int) $r->credits_total - (int) $r->credits_used);
    }
  }

  // ✅ NOVO: URL base de paginação mantendo apenas filtros relevantes
  $keep = ['service_id'];
  if ($is_admin && isset($_GET['user_id']) && (int) $_GET['user_id'] > 0) {
    $keep[] = 'user_id'; // só mantém se admin estiver usando
  }
  $base_url = remove_query_arg(['pg'], esc_url_raw(add_query_arg($keep, home_url(add_query_arg([], $GLOBALS['wp']->request ?? '')))));
  // fallback mais simples se preferir:
  // $base_url = remove_query_arg(['pg'], esc_url_raw(add_query_arg($keep)));

  ob_start(); ?>

  <div style="max-width:1100px;margin:0 auto">
    <div style="border:1px solid #e5e7eb;border-radius:12px;padding:14px;margin:12px 0;background:#fff">
      <div style="display:flex;justify-content:space-between;align-items:flex-end;gap:16px;flex-wrap:wrap">
        <div>
          <div style="opacity:.75">Assinaturas do usuário</div>
          <div style="font-weight:800">
            <?php echo esc_html($u->display_name); ?>
            <span style="opacity:.6;font-weight:600">(#<?php echo (int) $u->ID; ?>)</span>
          </div>
          <?php if (!$is_child && !$is_admin): ?>

            <div style="margin-top:6px;color:#8a5a00">
              Atenção: Assinaturas normalmente são apenas para master.
            </div>
          <?php endif; ?>

          <?php if ($is_admin): ?>
            <div style="margin-top:6px;color:#8a5a00">
              Atenção: Assinaturas são apenas para master.
            </div>
          <?php endif; ?>
        </div>

        <!--<div style="text-align:right">
            <div style="opacity:.75">Resumo</div>
            <div style="font-weight:700">
              Total: <?php #echo (int) $sum_total; 
                      ?> • Usado: <?php #echo (int) $sum_used; 
                                  ?>
              <?php #echo (int) $sum_current_active; 
              ?>
            </div>
          </div>-->
      </div>
    </div>

    <div style="border:1px solid #e5e7eb;border-radius:12px;overflow:hidden;background:#fff">
      <div
        style="padding:12px 14px;border-bottom:1px solid #e5e7eb;display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap">
        <div style="font-weight:800">Créditos por Assinaturas</div>
        <div style="opacity:.75"><?php echo (int) $total_rows; ?> contrato(s)</div>
      </div>

      <div style="overflow:auto">
        <table style="width:100%;border-collapse:collapse;min-width:860px">
          <thead>
            <tr style="background:#f9fafb;text-align:left">
              <th style="padding:10px;border-bottom:1px solid #e5e7eb">Nº contrato</th>
              <th style="padding:10px;border-bottom:1px solid #e5e7eb">Serviço</th>
              <th style="padding:10px;border-bottom:1px solid #e5e7eb">Vencimento</th>
              <th style="padding:10px;border-bottom:1px solid #e5e7eb">Créditos totais</th>
              <th style="padding:10px;border-bottom:1px solid #e5e7eb">Créditos disponíveis</th>
              <th style="padding:10px;border-bottom:1px solid #e5e7eb">Créditos usados</th>
              <th style="padding:10px;border-bottom:1px solid #e5e7eb">Status</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($rows)): ?>
              <tr>
                <td colspan="6" style="padding:14px">Nenhuma assinatura encontrada.</td>
              </tr>
            <?php else: ?>
              <?php foreach ($rows as $r):
                $total = (int) $r->credits_total;
                $used = (int) $r->credits_used;
                $current = max(0, $total - $used);
                $status = $calc_status($r->valid_until, $total, $used);
                $svc = $r->service_name ?: ('Serviço #' . (int) $r->service_id);
                $venc = $r->valid_until ? date_i18n('d/m/Y H:i', strtotime($r->valid_until)) : '-';
              ?>
                <tr>
                  <td style="padding:10px;border-bottom:1px solid #f0f2f5;font-weight:700">#<?php echo (int) $r->id; ?></td>
                  <td style="padding:10px;border-bottom:1px solid #f0f2f5"><?php echo esc_html($svc); ?></td>
                  <td style="padding:10px;border-bottom:1px solid #f0f2f5"><?php echo esc_html($venc); ?></td>
                  <td style="padding:10px;border-bottom:1px solid #f0f2f5"><?php echo (int) $total; ?></td>
                  <td style="padding:10px;border-bottom:1px solid #f0f2f5"><?php echo (int) $current; ?></td>
                  <td style="padding:10px;border-bottom:1px solid #f0f2f5">
                    <?php echo (int) $total - (int) $current; ?>
                  </td>

                  <td style="padding:10px;border-bottom:1px solid #f0f2f5"><?php echo $badge($status); ?></td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>

      <?php if ($total_pages > 1): ?>
        <div style="padding:12px 14px;display:flex;justify-content:flex-end;gap:8px;align-items:center">
          <?php $prev = max(1, $page - 1);
          $next = min($total_pages, $page + 1); ?>
          <a href="<?php echo esc_url(add_query_arg('pg', $prev, $base_url)); ?>"
            style="text-decoration:none;padding:8px 10px;border:1px solid #e5e7eb;border-radius:10px">←</a>
          <div style="opacity:.8">Página <?php echo (int) $page; ?> de <?php echo (int) $total_pages; ?></div>
          <a href="<?php echo esc_url(add_query_arg('pg', $next, $base_url)); ?>"
            style="text-decoration:none;padding:8px 10px;border:1px solid #e5e7eb;border-radius:10px">→</a>
        </div>
      <?php endif; ?>
    </div>
  </div>

<?php
  return ob_get_clean();
}


##  Extrato 2
add_shortcode('acme_credits_snapshot2', function () {

  if (!is_user_logged_in())
    return '';

  global $wpdb;

  // Tabelas reais do seu banco
  $txT = 'wp_credit_transactions';
  $servicesT = 'wp_services';

  $viewer_id = get_current_user_id();

  // Usuário alvo: na edição do usuário (wp-admin/user-edit.php?user_id=123)
  $target_id = $viewer_id;
  if (is_admin() && isset($_GET['user_id'])) {
    $target_id = (int) $_GET['user_id'];
  }

  // Segurança: só admin pode ver outro usuário
  if ($target_id !== $viewer_id && !current_user_can('manage_options')) {
    $target_id = $viewer_id;
  }

  $is_admin_view = current_user_can('manage_options');


  $target_user = get_user_by('id', $target_id);
  $target_name = $target_user ? $target_user->display_name : ('#' . $target_id);

  // 1) Snapshot por serviço (saldo atual por serviço)
  // 1) Snapshot por serviço (saldo atual por serviço)
  $snapshot = $wpdb->get_results($wpdb->prepare("
  SELECT
    s.id   AS service_id,
    s.name AS service_name,
    SUM(
      CASE
        WHEN t.type = 'debit' THEN -ABS(t.credits)
        ELSE ABS(t.credits)
      END
    ) AS saldo
  FROM {$txT} t
  INNER JOIN {$servicesT} s ON s.id = t.service_id
  WHERE (%d = 1 OR t.user_id = %d)
    AND t.status = 'success'
    AND t.type IN ('credit','debit','refund','adjust')
  GROUP BY s.id, s.name
  ORDER BY s.name ASC
", $is_admin_view ? 1 : 0, $target_id));



  $usersT = $wpdb->users;

  // 2) Histórico (linhas simples do usuário)
  // 2) Histórico (linhas simples do usuário)
  $history_limit = 100;

  // Puxa em ordem ASC para calcular saldo acumulado
  $history_rows = $wpdb->get_results($wpdb->prepare("
    SELECT
      t.id,
      t.created_at,
      t.type,
      t.credits,
      t.user_id AS tx_user_id,
      u.display_name AS tx_user_name,
      u.user_email   AS tx_user_email,
      t.service_id,
      COALESCE(s.name, t.service_name, t.service_slug, '—') AS service_name
    FROM {$txT} t
    LEFT JOIN {$usersT} u ON u.ID = t.user_id
    LEFT JOIN {$servicesT} s ON s.id = t.service_id
    WHERE (%d = 1 OR t.user_id = %d)
      AND t.status = 'success'
      AND t.type IN ('credit','debit','refund','adjust')
    ORDER BY t.id ASC
    LIMIT %d
  ", $is_admin_view ? 1 : 0, $target_id, $history_limit), ARRAY_A);

  $lotsT = $wpdb->prefix . 'credit_lots';
  $now_mysql = current_time('mysql');

  $available_by_service = $wpdb->get_results($wpdb->prepare("
  SELECT
    l.service_id,
    COALESCE(s.name, '—') AS service_name,
    COALESCE(SUM(GREATEST(l.credits_total - l.credits_used, 0)), 0) AS available
  FROM {$lotsT} l
  LEFT JOIN {$servicesT} s ON s.id = l.service_id
  WHERE l.owner_user_id = %d
    AND (l.expires_at IS NULL OR l.expires_at >= %s)
  GROUP BY l.service_id
  ORDER BY service_name ASC
", $target_id, $now_mysql), ARRAY_A);






  // Calcula saldo acumulado POR SERVIÇO
  $running = []; // [service_id => saldo]
  foreach ($history_rows as &$h) {
    $sid = (int) ($h['service_id'] ?? 0);

    $delta = 0;
    if (($h['type'] ?? '') === 'debit') {
      $delta = -abs((int) $h['credits']);
    } else {
      // credit/refund/adjust entram como positivo
      $delta = abs((int) $h['credits']);
    }

    if (!isset($running[$sid]))
      $running[$sid] = 0;
    $running[$sid] += $delta;

    // saldo total após esta movimentação
    $h['balance_after'] = $running[$sid];
    // se você quiser manter o delta também:
    $h['delta'] = $delta;
  }
  unset($h);

  // Exibe do mais novo pro mais antigo
  $history = array_reverse($history_rows);


  ob_start();
?>
  <style>
    .acme-wrap {
      font-family: system-ui, -apple-system, BlinkMacSystemFont;
      max-width: auto;
    }

    /* ===== Snapshot ===== */
    .acme-snapshot {
      background: #0b1220;
      padding: 16px 20px;
      border-radius: 12px;
    }

    .acme-snapshot-title {
      color: #9ca3af;

      margin-bottom: 12px;
      text-transform: uppercase;
      letter-spacing: .08em;
      display: flex;
      justify-content: space-between;
      gap: 12px;
      flex-wrap: wrap;
    }

    .acme-snapshot-title strong {
      color: #e5e7eb;
      font-weight: 600;
    }

    .acme-snapshot-row {
      display: flex;
      justify-content: space-between;
      align-items: center;
      padding: 8px 0;
      border-bottom: 1px solid rgba(255, 255, 255, .06);

      gap: 10px;
    }

    .acme-snapshot-row:last-child {
      border-bottom: none;
    }

    .acme-snapshot-name {
      color: #e5e7eb;
      font-weight: 600;
    }

    .acme-snapshot-saldo {
      font-weight: 700;
      white-space: nowrap;
    }

    .acme-pos {
      color: #22c55e;
    }

    .acme-neg {
      color: #ef4444;
    }

    /* ===== Histórico ===== */
    .acme-history {
      margin-top: 14px;
      border: 1px solid #e5e7eb;
      border-radius: 12px;
      overflow: hidden;
      background: #fff;
    }

    .acme-history-head {
      padding: 12px 14px;
      border-bottom: 1px solid #eef2f7;
      display: flex;
      justify-content: space-between;
      align-items: baseline;
      gap: 10px;
      flex-wrap: wrap;
    }

    .acme-history-title {
      font-weight: 700;
      color: #111827;

    }

    .acme-history-sub {

      opacity: .75;
    }

    .acme-table {
      width: 100%;
      border-collapse: collapse;

    }

    .acme-table th {
      text-align: left;
      padding: 10px 12px;
      border-bottom: 1px solid #eef2f7;
      background: #fafafa;
      font-weight: 600;
      color: #111827;
      white-space: nowrap;
    }

    .acme-table td {
      padding: 10px 12px;
      border-bottom: 1px solid #f1f5f9;
      vertical-align: top;
    }

    .acme-right {
      text-align: right;
      white-space: nowrap;
    }

    .acme-delta-pos {
      color: #16a34a;
      font-weight: 700;
    }

    .acme-delta-neg {
      color: #dc2626;
      font-weight: 700;
    }

    .acme-muted {
      opacity: .75;
    }

    .acme-empty {
      padding: 12px 14px;
    }
  </style>

  <div class="acme-wrap">

    <div class="acme-snapshot">
      <div class="acme-snapshot-title">
        <span>Saldo disponível (para uso)</span>
        <span class="acme-muted">Usuário: <strong><?php echo esc_html($target_name); ?></strong></span>
      </div>

      <?php if (empty($available_by_service)): ?>
        <div class="acme-muted">Sem saldo disponível.</div>
      <?php else: ?>
        <?php foreach ($available_by_service as $r): ?>
          <div class="acme-snapshot-row">
            <span class="acme-snapshot-name"><?php echo esc_html($r['service_name']); ?></span>
            <span class="acme-snapshot-saldo acme-pos">
              <?php echo number_format((int) $r['available'], 0, ',', '.'); ?>
            </span>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>


    <div class="acme-history">
      <div class="acme-history-head">
        <div class="acme-history-title">Histórico</div>
        <div class="acme-history-sub">Últimas <?php echo (int) $history_limit; ?> movimentações (success)</div>

        <div class=""><?php echo do_shortcode('[acme_export_button report="credits_extract_last20"]'); ?></div>

      </div>

      <?php if (empty($history)): ?>
        <div class="acme-empty">Nenhuma movimentação encontrada.</div>
      <?php else: ?>


        <div style="overflow:auto">
          <table class="acme-table">
            <thead>
              <tr>
                <th>Usuário</th>
                <th>Serviço</th>
                <th class="acme-right">Saldo</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($history as $h):

                $balance_after = (int) ($h['balance_after'] ?? 0);
                $cls = ($balance_after < 0) ? 'acme-delta-neg' : 'acme-delta-pos'; // reutiliza seus estilos
              ?>
                <tr>
                  <td>
                    <?php if ($is_admin_view): ?>
                      <strong><?php echo esc_html($h['tx_user_name'] ?: ('#' . (int) $h['tx_user_id'])); ?></strong><br>
                      <span class="acme-muted">#<?php echo (int) $h['tx_user_id']; ?></span>
                    <?php else: ?>
                      <strong><?php echo esc_html($target_name); ?></strong><br>
                      <span class="acme-muted">#<?php echo (int) $target_id; ?></span>
                    <?php endif; ?>
                  </td>

                  <td><?php echo esc_html($h['service_name'] ?? '—'); ?></td>

                  <td class="acme-right <?php echo esc_attr($cls); ?>">
                    <?php echo esc_html(number_format($balance_after, 0, ',', '.')); ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>



      <?php endif; ?>
    </div>

  </div>
<?php

  return ob_get_clean();
});


/**
 * Painel/Historico CLT
 * Shortcode: [acme_clt_panel]
 *
 * + Paginação (GET: clt_page)
 * - mantém filtros na navegação
 * - conta total (COUNT) com o MESMO WHERE
 */

if (!function_exists('acme_cpf_hash')) {
  function acme_cpf_hash(string $cpf_numbers): string
  {
    $cpf_numbers = preg_replace('/\D/', '', $cpf_numbers);
    return hash('sha256', $cpf_numbers);
  }
}

add_shortcode('acme_clt_panel', function () {

  if (!is_user_logged_in()) {
    return '<div style="padding:12px;border:1px solid #f2c;border-radius:10px;">Faça login para ver seu histórico.</div>';
  }

  global $wpdb;
  $t = $wpdb->prefix . 'clt_requests';

  $me = get_current_user_id();
  $is_admin = current_user_can('manage_options');

  // =========================
  // 0) PAGINAÇÃO (GET)
  // =========================
  $per_page = 30;
  $page = isset($_GET['clt_page']) ? max(1, (int)$_GET['clt_page']) : 1;
  $offset = ($page - 1) * $per_page;

  // =========================
  // 1) LER FILTROS (GET)
  // =========================
  $f_status    = isset($_GET['clt_status']) ? sanitize_text_field($_GET['clt_status']) : '';
  $f_cpf_raw   = isset($_GET['clt_cpf']) ? sanitize_text_field($_GET['clt_cpf']) : '';
  $f_user      = isset($_GET['clt_user']) ? sanitize_text_field($_GET['clt_user']) : '';
  $f_date_from = isset($_GET['clt_date_from']) ? sanitize_text_field($_GET['clt_date_from']) : '';
  $f_date_to   = isset($_GET['clt_date_to']) ? sanitize_text_field($_GET['clt_date_to']) : '';
  $f_elegibilidade = isset($_GET['clt_elegibilidade']) ? sanitize_text_field($_GET['clt_elegibilidade']) : '';

  // Status permitido
  $allowed_status = ['pending', 'completed', 'failed'];
  if ($f_status && !in_array($f_status, $allowed_status, true)) {
    $f_status = '';
  }

  // Datas (input type="date" => YYYY-MM-DD)
  $dt_from = '';
  $dt_to = '';
  if ($f_date_from && preg_match('/^\d{4}-\d{2}-\d{2}$/', $f_date_from)) {
    $dt_from = $f_date_from . ' 00:00:00';
  }
  if ($f_date_to && preg_match('/^\d{4}-\d{2}-\d{2}$/', $f_date_to)) {
    $dt_to = $f_date_to . ' 23:59:59';
  }

  // =========================
  // 2) MONTAR WHERE (CASCATA)
  // =========================
  $where = [];
  $params = [];

  // Usuário normal: sempre vê só o dele
  if (!$is_admin) {
    $where[] = 't.user_id = %d';
    $params[] = $me;
  } else {
    // Admin: filtro por usuário pode ser ID (exato) ou nome/email (LIKE)
    if ($f_user !== '') {
      if (ctype_digit($f_user)) {
        $where[] = 't.user_id = %d';
        $params[] = (int)$f_user;
      } else {
        $like = '%' . $wpdb->esc_like($f_user) . '%';
        $where[] = '(u.display_name LIKE %s OR u.user_email LIKE %s)';
        $params[] = $like;
        $params[] = $like;
      }
    }
  }

  // Status
  if ($f_status) {
    $where[] = 't.status = %s';
    $params[] = $f_status;
  }

  // Elegibilidade (campo virtual)
  if ($f_elegibilidade === 'NaoElegivel') {
    $where[] = "(
      t.error_code = 'nao_elegivel'
      OR (t.status = 'completed' AND (t.response_json IS NULL OR t.response_json = ''))
    )";
  }

  if ($f_elegibilidade === 'Elegivel') {
    $where[] = "(
      t.status != 'pending'
      AND (t.error_code IS NULL OR t.error_code <> 'nao_elegivel')
      AND NOT (t.status = 'completed' AND (t.response_json IS NULL OR t.response_json = ''))
    )";
  }

  // CPF (EXATO por hash quando 11 dígitos; parcial por cpf_masked LIKE)
  if ($f_cpf_raw !== '') {
    $cpf_numbers = preg_replace('/\D/', '', $f_cpf_raw);

    if (strlen($cpf_numbers) === 11) {
      $where[] = 't.cpf_hash = %s';
      $params[] = acme_cpf_hash($cpf_numbers);
    } else {
      $likeCpf = '%' . $wpdb->esc_like($f_cpf_raw) . '%';
      $where[] = 't.cpf_masked LIKE %s';
      $params[] = $likeCpf;
    }
  }

  // Datas
  if ($dt_from && $dt_to) {
    $where[] = '(t.created_at BETWEEN %s AND %s)';
    $params[] = $dt_from;
    $params[] = $dt_to;
  } elseif ($dt_from) {
    $where[] = 't.created_at >= %s';
    $params[] = $dt_from;
  } elseif ($dt_to) {
    $where[] = 't.created_at <= %s';
    $params[] = $dt_to;
  }

  $where_sql = '';
  if (!empty($where)) {
    $where_sql = 'WHERE ' . implode(' AND ', $where);
  }

  // =========================
  // 3) TOTAL (COUNT) p/ paginação
  // =========================
  $sql_count = "
    SELECT COUNT(*)
    FROM {$t} t
    LEFT JOIN {$wpdb->users} u ON u.ID = t.user_id
    {$where_sql}
  ";
  if (!empty($params)) {
    $sql_count = $wpdb->prepare($sql_count, $params);
  }
  $total = (int)$wpdb->get_var($sql_count);
  $total_pages = max(1, (int)ceil($total / $per_page));

  // Se alguém colar page maior que o total, joga pra última
  if ($page > $total_pages) {
    $page = $total_pages;
    $offset = ($page - 1) * $per_page;
  }

  // =========================
  // 4) QUERY FINAL (LIMIT/OFFSET)
  // =========================
  $sql = "
    SELECT t.*, u.display_name, u.user_email
    FROM {$t} t
    LEFT JOIN {$wpdb->users} u ON u.ID = t.user_id
    {$where_sql}
    ORDER BY t.created_at DESC
    LIMIT %d OFFSET %d
  ";

  $params_page = $params;
  $params_page[] = (int)$per_page;
  $params_page[] = (int)$offset;

  $sql = $wpdb->prepare($sql, $params_page);
  $rows = $wpdb->get_results($sql, ARRAY_A);

  $nonce_pdf = wp_create_nonce('acme_clt_pdf_nonce');

  // =========================
  // 5) UI
  // =========================
  $out = '';
  $out .= function_exists('acme_ui_panel_css') ? acme_ui_panel_css() : '';

  $base_url = get_permalink();
  $clear_url = esc_url($base_url);

  $out .= '<div class="acme-panel">';
  $out .= '<div class="acme-panel-h">';
  $out .= '<div>';
  $out .= '<div class="acme-panel-title">Histórico de Consultas CLT</div>';
  $out .= '<div class="acme-panel-sub">Mostrando ' . (int)min($total, ($offset + 1)) . '–' . (int)min($total, ($offset + count($rows))) . ' de ' . (int)$total . ' registros.</div>';
  $out .= '</div>';
  $out .= '<div class="acme-actions">';
  $out .= '<a class="acme-btn" href="' . esc_url(add_query_arg([], $base_url)) . '">Atualizar</a>';
  $out .= '<a class="acme-btn" href="' . $clear_url . '">Limpar filtros</a>';
  $out .= do_shortcode('[acme_clt_panel_export label="Baixar Relatório" class="acme-btn"]');
  $out .= '<button class="acme-btn-icon" type="submit" form="acme-clt-filter-form" aria-label="Pesquisar">';
  $out .= '<svg viewBox="0 0 24 24" fill="none" aria-hidden="true">'
    . '<path d="M10.5 18a7.5 7.5 0 1 1 0-15 7.5 7.5 0 0 1 0 15Z" stroke="currentColor" stroke-width="2" />'
    . '<path d="M16.5 16.5 21 21" stroke="currentColor" stroke-width="2" stroke-linecap="round" />'
    . '</svg>';
  $out .= '</button>';
  $out .= '</div>';
  $out .= '</div>';

  // ===== Filtros (GET) =====
  // ===== Filtros (GET) =====
  $out .= '<div class="acme-panel-filters">';
  $out .= '<form id="acme-clt-filter-form" method="get" action="' . esc_url($base_url) . '" class="acme-filter-grid">'; //$out .= '<form method="get" action="' . esc_url($base_url) . '" class="acme-filter-grid">';

  // Quando filtra, sempre volta para a página 1
  $out .= '<input type="hidden" name="clt_page" value="1" />';

  // Linha única: 5 campos + botão (igual print)
  $out .= '<div class="acme-filter-row-6">';

  $out .= '<div class="acme-field">';
  $out .= '<label class="acme-muted">Status</label>';
  $out .= '<select class="acme-input" name="clt_status">';
  $out .= '<option value="">Todos</option>';
  foreach ($allowed_status as $st) {
    $out .= '<option value="' . esc_attr($st) . '"' . selected($f_status, $st, false) . '>' . esc_html($st) . '</option>';
  }
  $out .= '</select>';
  $out .= '</div>';

  $out .= '<div class="acme-field">';
  $out .= '<label class="acme-muted">Elegibilidade</label>';
  $out .= '<select class="acme-input" name="clt_elegibilidade">';
  $out .= '<option value="">Todos</option>';
  $out .= '<option value="Elegivel"' . selected($f_elegibilidade, 'Elegivel', false) . '>Elegível</option>';
  $out .= '<option value="NaoElegivel"' . selected($f_elegibilidade, 'NaoElegivel', false) . '>Não Elegível</option>';
  $out .= '</select>';
  $out .= '</div>';

  $out .= '<div class="acme-field">';
  $out .= '<label class="acme-muted">CPF</label>';
  $out .= '<input class="acme-input" type="text" name="clt_cpf" value="' . esc_attr($f_cpf_raw) . '" placeholder="ex.: 94132038653 ou 941.***" />';
  $out .= '</div>';

  $out .= '<div class="acme-field">';
  $out .= '<label class="acme-muted">Data (de)</label>';
  $out .= '<input class="acme-input" type="date" name="clt_date_from" value="' . esc_attr($f_date_from) . '" />';
  $out .= '</div>';

  $out .= '<div class="acme-field">';
  $out .= '<label class="acme-muted">Data (até)</label>';
  $out .= '<input class="acme-input" type="date" name="clt_date_to" value="' . esc_attr($f_date_to) . '" />';
  $out .= '</div>';


  $out .= '</form>';
  $out .= '</div>'; // panel-filters
  $out .= '<br>';
  if (empty($rows)) {
    $out .= '<div style="padding:14px 16px;color:#64748b;">Nenhuma consulta encontrada com esses filtros.</div>';
    $out .= '</div>';
    return $out;
  }

  // ===== Tabela =====
  $out .= '<table class="acme-table" style="font-size:13px">';
  $out .= '<thead><tr>
    <th>Criado</th>
    <th>Usuário</th>
    <th>CPF</th>
    <th>Elegibilidade</th>
    <th>Status</th>
    <th>Situação (Se erro)</th>
    <th>PDF</th>
  </tr></thead><tbody>';

  foreach ($rows as $r) {
    $status = $r['status'] ?? 'pending';

    $created = $r['created_at'] ?? '';
    $created = $created ? date_i18n('d/m/Y H:i', strtotime($created)) : '';

    $badgeClass = 'acme-badge-pending';
    if ($status === 'completed') $badgeClass = 'acme-badge-completed';
    if ($status === 'failed') $badgeClass = 'acme-badge-failed';

    $user_name = $r['display_name'] ?? ('#' . (int)$r['user_id']);
    $cpf = $r['cpf_masked'] ?? '***';

    $elegibilidade = "";
    if ($r['status'] != 'pending') {
      if (($r['error_code'] ?? '') === 'nao_elegivel') {
        $elegibilidade = "Não Elegível";
      } else if (($r['status'] ?? '') === 'completed' && ($r['response_json'] ?? '') === "") {
        $elegibilidade = "Não Elegível";
      } else {
        $elegibilidade = "Elegível";
      }
    }

    $pdf = '—';
    if ($status === 'completed' && !empty($r['request_id']) && ($r['response_json'] ?? '') !== '') {
      $pdf_url = admin_url('admin-ajax.php?action=acme_clt_pdf_request&_wpnonce=' . $nonce_pdf . '&request_id=' . rawurlencode($r['request_id']));
      $pdf = '<a class="acme-btn" target="_blank" rel="noopener" href="' . esc_url($pdf_url) . '">Baixar PDF</a>';
    }

    $error = $r['error_message'] ?? '';

    $status_valor = ($status === 'completed') ? 'Completo' : (($status === 'failed') ? 'Falha' : 'Pendente');

    $out .= '<tr>';
    $out .= '<td class="acme-muted">' . esc_html($created) . '</td>';
    $out .= '<td><strong>' . esc_html($user_name) . '</strong><br><span class="acme-muted">#' . (int)$r['user_id'] . '</span></td>';
    $out .= '<td>' . esc_html($cpf) . '</td>';
    $out .= '<td class="acme-muted">' . esc_html($elegibilidade) . '</td>';
    $out .= '<td><span class="acme-badge ' . esc_attr($badgeClass) . '">' . esc_html($status_valor) . '</span></td>';
    $out .= '<td>' . esc_html($error) . '</td>';
    $out .= '<td>' . $pdf . '</td>';
    $out .= '</tr>';
  }

  $out .= '</tbody></table>';

  // =========================
  // 6) CONTROLES DE PAGINAÇÃO
  // =========================
  if ($total_pages > 1) {

    $base_args = $_GET;
    unset($base_args['clt_page']);

    $mk = function ($p) use ($base_url, $base_args) {
      $args = $base_args;
      $args['clt_page'] = $p;
      return esc_url(add_query_arg($args, $base_url));
    };

    $prev = max(1, $page - 1);
    $next = min($total_pages, $page + 1);

    $out .= '<div style="display:flex;justify-content:space-between;align-items:center;padding:12px 16px;border-top:1px solid #eef2f7;">';
    $out .= '<div class="acme-muted">Página <strong>' . (int)$page . '</strong> de <strong>' . (int)$total_pages . '</strong></div>';
    $out .= '<div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">';

    if ($page > 1) {
      $out .= '<a class="acme-btn" href="' . $mk(1) . '">« Primeira</a>';
      $out .= '<a class="acme-btn" href="' . $mk($prev) . '">‹ Anterior</a>';
    } else {
      $out .= '<span class="acme-btn" style="opacity:.5;pointer-events:none;">« Primeira</span>';
      $out .= '<span class="acme-btn" style="opacity:.5;pointer-events:none;">‹ Anterior</span>';
    }

    if ($page < $total_pages) {
      $out .= '<a class="acme-btn" href="' . $mk($next) . '">Próxima ›</a>';
      $out .= '<a class="acme-btn" href="' . $mk($total_pages) . '">Última »</a>';
    } else {
      $out .= '<span class="acme-btn" style="opacity:.5;pointer-events:none;">Próxima ›</span>';
      $out .= '<span class="acme-btn" style="opacity:.5;pointer-events:none;">Última »</span>';
    }

    $out .= '</div>';
    $out .= '</div>';
  }

  $out .= '</div>';

  return $out;
});
