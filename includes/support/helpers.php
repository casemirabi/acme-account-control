<?php
if (!defined('ABSPATH')) exit;

/**
 * ============================================================
 * ACME — Helpers + PDF + CLT Ajax + Créditos (Lotes)
 * ============================================================
 *
 * ✅ Ajustes principais (FRONT + PDF) para o formato do BANCO (response_json):
 *
 * response_json esperado (exemplo simplificado):
 * {
 *   "dados": [
 *     {
 *       "ok": true,
 *       "cpf": "000****07",
 *       "vinculos": [ { "elegivel": true, ... } ],
 *       "margem": {
 *         "valorMargemDisponivel": 636.09,
 *         "valorMargemBase": 1817.4,
 *         "dataAdmissao": "2024-11-01",
 *         "dataNascimento": "1979-01-05",
 *         "sexo": "M"
 *       },
 *       "propostas": {
 *         "capturedResponse": { "body": "[{...},{...}]" }
 *       }
 *     }
 *   ]
 * }
 *
 * REGRAS:
 * - Elegível: se existir qualquer vinculo com "elegivel": true  => Elegível
 * - Se não existir => Não elegível
 *
 * CAMPOS VISÍVEIS NO FRONT:
 * - valorMargemDisponivel (margem.valorMargemDisponivel)
 * - valorMargemBase (margem.valorMargemBase)
 * - dataNascimento (margem.dataNascimento)
 * - sexo (margem.sexo) => F: Feminino, M: Masculino
 * - dataAdmissao (margem.dataAdmissao)
 * - cpf (root.cpf)
 * - nome (root.nome) — "Dados restringidos" quando inelegível
 *
 * - Quando houver propostas.capturedResponse.body:
 *   Trocar o título do <summary> de "Ver vínculos retornados" para "Simulações"
 *   e exibir as simulações (parse do JSON do body).
 *
 * - renderResultCard: só executa quando status final (completed/failed).
 * - JS: polling aguardando até 1:30 (90s).
 * ============================================================
 */

/* ============================================================
 * 1) HELPERS GERAIS
 * ============================================================
 */

if (!function_exists('acme_master_admin_id')) {
  function acme_master_admin_id(): int
  {
    return 1;
  }
}

if (!function_exists('acme_user_has_role')) {
  function acme_user_has_role($user, string $role): bool
  {
    if (!$user) return false;
    $roles = is_object($user) ? (array) ($user->roles ?? []) : [];
    return in_array($role, $roles, true);
  }
}

if (!function_exists('acme_sanitize_phone')) {
  function acme_sanitize_phone(string $phone): string
  {
    return preg_replace('/[^0-9+]/', '', $phone);
  }
}

if (!function_exists('acme_debug_enabled')) {
  function acme_debug_enabled(): bool
  {
    return defined('ACME_DEBUG') && ACME_DEBUG;
  }
}

if (!function_exists('acme_debug_dump')) {
  function acme_debug_dump(array $data): string
  {
    return '<pre style="white-space:pre-wrap;background:#111;color:#0f0;padding:12px;border-radius:10px;overflow:auto;max-height:280px">'
      . esc_html(print_r($data, true)) .
      '</pre>';
  }
}

/* ============================================================
 * 1.1) DÉBITO 1x POR CPF/DIA (por usuário) — helpers
 * ============================================================
 *
 * Regra: o débito deve ocorrer apenas 1 vez por CPF por usuário dentro do mesmo dia.
 * Implementação: request_id determinístico (user_id + service_slug + YYYY-MM-DD + sha256(CPF_digits)).
 * Segurança: NÃO armazena CPF puro no request_id (somente hash).
 */

if (!function_exists('acme_cpf_digits')) {
  function acme_cpf_digits(string $cpf): string
  {
    return preg_replace('/\D+/', '', (string) $cpf);
  }
}

if (!function_exists('acme_cpf_hash')) {
  function acme_cpf_hash(string $cpf): string
  {
    $digits = acme_cpf_digits($cpf);
    return hash('sha256', $digits);
  }
}

if (!function_exists('acme_daily_debit_request_id')) {
  /**
   * Gera request_id determinístico para deduplicar débito por CPF/dia/usuário.
   *
   * @param int $user_id
   * @param string $service_slug Ex: 'clt'
   * @param string $cpf CPF (qualquer formato). Usamos apenas dígitos e aplicamos sha256.
   * @param string|null $ymd Dia no formato 'YYYY-MM-DD'. Se null, usa wp_date('Y-m-d') (timezone do site).
   */
  function acme_daily_debit_request_id(int $user_id, string $service_slug, string $cpf, ?string $ymd = null): string
  {
    $user_id = (int) $user_id;
    $service_slug = (string) $service_slug;
    $ymd = $ymd ? (string) $ymd : wp_date('Y-m-d'); // timezone do WP
    $cpf_h = acme_cpf_hash($cpf);

    // base determinística -> hash final (64 hex)
    $base = $user_id . '|' . $service_slug . '|' . $ymd . '|' . $cpf_h;
    return hash('sha256', $base);
  }
}

/* ============================================================
 * 2) HELPERS DE TABELAS
 * ============================================================
 */

if (!function_exists('acme_table_links')) {
  function acme_table_links(): string
  {
    global $wpdb;
    return $wpdb->prefix . 'account_links';
  }
}
if (!function_exists('acme_table_status')) {
  function acme_table_status(): string
  {
    global $wpdb;
    return $wpdb->prefix . 'account_status';
  }
}
if (!function_exists('acme_table_services')) {
  function acme_table_services(): string
  {
    global $wpdb;
    return $wpdb->prefix . 'services';
  }
}
if (!function_exists('acme_table_wallet')) {
  function acme_table_wallet(): string
  {
    global $wpdb;
    return $wpdb->prefix . 'wallet';
  }
}
if (!function_exists('acme_table_credit_transactions')) {
  function acme_table_credit_transactions(): string
  {
    global $wpdb;
    return $wpdb->prefix . 'credit_transactions';
  }
}

/* ============================================================
 * 3) PDF (DOMPDF) — Loader + Stream
 * ============================================================
 */

if (!function_exists('acme_load_dompdf')) {
  function acme_load_dompdf(): bool
  {
    if (class_exists('\Dompdf\Dompdf')) return true;

    // usa a constante REAL do seu plugin
    $autoload = trailingslashit(ACME_ACC_PATH) . 'lib/dompdf/autoload.inc.php';
    if (file_exists($autoload)) {
      require_once $autoload;
      return class_exists('\Dompdf\Dompdf');
    }
    return false;
  }
}

if (!function_exists('acme_pdf_is_available')) {
  function acme_pdf_is_available(): bool
  {
    return acme_load_dompdf();
  }
}

if (!function_exists('acme_pdf_stream_html')) {
  function acme_pdf_stream_html(string $html, string $filename = 'relatorio.pdf'): void
  {
    if (!acme_pdf_is_available()) {
      wp_die('Dompdf não disponível (autoload não encontrado ou não carregou).');
    }

    $dompdf = new \Dompdf\Dompdf([
      'isRemoteEnabled' => true,
      'isHtml5ParserEnabled' => true,
    ]);

    $dompdf->loadHtml($html, 'UTF-8');
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();

    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');

    echo $dompdf->output();
    exit;
  }
}

/* ============================================================
 * 4) TEMP — Helpers para consumo via API
 * ============================================================
 */

if (!function_exists('acme_allowance_has_credit')) {
  function acme_allowance_has_credit(int $child_user_id, int $service_id): array
  {
    global $wpdb;
    $t = $wpdb->prefix . 'credit_allowances';

    $row = $wpdb->get_row($wpdb->prepare(
      "SELECT id, master_user_id, credits_total, credits_used, expires_at, status
       FROM {$t}
       WHERE child_user_id=%d AND service_id=%d
       LIMIT 1",
      $child_user_id,
      $service_id
    ), ARRAY_A);

    if (!$row) return ['ok' => false, 'reason' => 'no_allowance'];
    if (($row['status'] ?? '') !== 'active') return ['ok' => false, 'reason' => 'inactive'];
    if (!empty($row['expires_at']) && strtotime($row['expires_at']) < time()) return ['ok' => false, 'reason' => 'expired'];

    $total = (int) $row['credits_total'];
    $used  = (int) $row['credits_used'];

    if (($total - $used) <= 0) return ['ok' => false, 'reason' => 'no_balance'];

    return ['ok' => true, 'row' => $row];
  }
}

if (!function_exists('acme_lots_has_credit')) {
  function acme_lots_has_credit(int $master_user_id, int $service_id): bool
  {
    global $wpdb;
    $t = $wpdb->prefix . 'credit_lots';
    $now = current_time('mysql');

    $id = (int) $wpdb->get_var($wpdb->prepare(
      "SELECT id
       FROM {$t}
       WHERE master_user_id=%d
         AND service_id=%d
         AND status='active'
         AND (expires_at IS NULL OR expires_at >= %s)
         AND credits_total > credits_used
       ORDER BY (expires_at IS NULL) ASC, expires_at ASC, id ASC
       LIMIT 1",
      $master_user_id,
      $service_id,
      $now
    ));

    return $id > 0;
  }
}

/* ============================================================
 * 5) LOG DA API
 * ============================================================
 */

if (!function_exists('acme_log_api')) {
  function acme_log_api($data)
  {
    global $wpdb;

    $table = $wpdb->prefix . 'api_logs';

    $insert = [
      'user_id'    => (int) ($data['user_id'] ?? 0),
      'service'    => (string) ($data['service'] ?? ''), // (mantido como está no seu arquivo)
      'api'        => (string) ($data['api'] ?? ''),
      'token'      => (string) ($data['token'] ?? ''),
      'status'     => (string) ($data['status'] ?? 'success'),
      'created_at' => current_time('mysql'),
    ];

    $ok = $wpdb->insert($table, $insert);

    if (!$ok) {
      error_log('ACME log_api INSERT failed: ' . $wpdb->last_error);
      return false;
    }

    return true;
  }
}

/* ============================================================
 * 6) AJAX — CONSULTA CLT (wp-admin e front)
 * ============================================================
 */

/*add_action('wp_ajax_acme_clt_query', 'acme_ajax_clt_query');

if (!function_exists('acme_ajax_clt_query')) {
  function acme_ajax_clt_query()
  {
    if (!is_user_logged_in()) {
      wp_send_json_error(['message' => 'Faça login para consultar.'], 401);
    }

    $nonce = $_POST['_wpnonce'] ?? '';
    if (!$nonce || !wp_verify_nonce($nonce, 'acme_clt_nonce')) {
      wp_send_json_error(['message' => 'Token inválido.'], 403);
    }

    $cpf = preg_replace('/\D+/', '', (string) ($_POST['cpf'] ?? ''));
    if (strlen($cpf) !== 11) {
      wp_send_json_error(['message' => 'CPF inválido.'], 422);
    }

    if (!function_exists('acme_service_clt')) {
      wp_send_json_error(['message' => 'Provider CLT não carregado.'], 500);
    }

    $result = acme_service_clt(get_current_user_id(), $cpf);

    if (is_array($result) && isset($result['error'])) {
      wp_send_json_error(['message' => $result['error']], 400);
    }

    wp_send_json_success(['data' => $result]);
  }
}*/

/* ============================================================
 * 7) JS FRONT (FORM CLT)
 * ============================================================
 */


/*add_action('wp_enqueue_scripts', function () {
  if (!is_user_logged_in()) return;

  $js_rel  = 'assets/js/acme-clt-form.js';
  $css_rel = 'assets/css/acme-clt.css';

  $js_path  = trailingslashit(ACME_ACC_PATH) . $js_rel;
  $css_path = trailingslashit(ACME_ACC_PATH) . $css_rel;

  $js_url  = trailingslashit(ACME_ACC_URL) . $js_rel;
  $css_url = trailingslashit(ACME_ACC_URL) . $css_rel;

  // versões por filemtime (cache-busting seguro)
  $js_ver  = file_exists($js_path) ? (string) filemtime($js_path) : null;
  $css_ver = file_exists($css_path) ? (string) filemtime($css_path) : null;


  // CSS
  wp_register_style('acme-clt-style', $css_url, [], $css_ver);
  wp_enqueue_style('acme-clt-style');

  // JS
  wp_register_script('acme-clt-form', $js_url, [], $js_ver, true);
  wp_enqueue_script('acme-clt-form');

  // Variáveis dinâmicas continuam no PHP
  wp_localize_script('acme-clt-form', 'ACME_CLT', [
    'restStart'  => rest_url('acme/v1/api-clt'),
    'restStatus' => rest_url('acme/v1/api-clt-status'),
    'restNonce'  => wp_create_nonce('wp_rest'),
    'pdfUrl'     => admin_url('admin-ajax.php?action=acme_clt_pdf_request&_wpnonce=' . wp_create_nonce('acme_clt_pdf_nonce')),
  ]);
});*/
add_action('wp_enqueue_scripts', function () {
  if (!is_user_logged_in()) return;

  // ===== CLT =====
  $js_rel  = 'assets/js/acme-clt-form.js';
  $css_rel = 'assets/css/acme-clt.css';

  $js_path  = trailingslashit(ACME_ACC_PATH) . $js_rel;
  $css_path = trailingslashit(ACME_ACC_PATH) . $css_rel;

  $js_url  = trailingslashit(ACME_ACC_URL) . $js_rel;
  $css_url = trailingslashit(ACME_ACC_URL) . $css_rel;

  $js_ver  = file_exists($js_path) ? (string) filemtime($js_path) : null;
  $css_ver = file_exists($css_path) ? (string) filemtime($css_path) : null;

  wp_register_style('acme-clt-style', $css_url, [], $css_ver);
  wp_enqueue_style('acme-clt-style');

  wp_register_script('acme-clt-form', $js_url, [], $js_ver, true);
  wp_enqueue_script('acme-clt-form');

  wp_localize_script('acme-clt-form', 'ACME_CLT', [
    'restStart'  => rest_url('acme/v1/api-clt'),
    'restStatus' => rest_url('acme/v1/api-clt-status'),
    'restNonce'  => wp_create_nonce('wp_rest'),
    'pdfUrl'     => admin_url('admin-ajax.php?action=acme_clt_pdf_request&_wpnonce=' . wp_create_nonce('acme_clt_pdf_nonce')),
  ]);

  // ===== INSS =====
  global $post;

  if (
    $post &&
    !empty($post->post_content) &&
    has_shortcode($post->post_content, 'acme_inss_form')
  ) {
    $inss_js_rel   = 'assets/js/acme-inss-form.js';
    $inss_css_rel  = 'assets/css/acme-inss.css';

    $inss_js_path  = trailingslashit(ACME_ACC_PATH) . $inss_js_rel;
    $inss_css_path = trailingslashit(ACME_ACC_PATH) . $inss_css_rel;

    $inss_js_url   = trailingslashit(ACME_ACC_URL) . $inss_js_rel;
    $inss_css_url  = trailingslashit(ACME_ACC_URL) . $inss_css_rel;

    $inss_js_ver   = file_exists($inss_js_path) ? (string) filemtime($inss_js_path) : null;
    $inss_css_ver  = file_exists($inss_css_path) ? (string) filemtime($inss_css_path) : null;

    $clt_css_rel  = 'assets/css/acme-clt.css';
    $clt_css_path = trailingslashit(ACME_ACC_PATH) . $clt_css_rel;
    $clt_css_url  = trailingslashit(ACME_ACC_URL) . $clt_css_rel;
    $clt_css_ver  = file_exists($clt_css_path) ? (string) filemtime($clt_css_path) : null;

    wp_register_style('acme-clt-style', $clt_css_url, [], $clt_css_ver);
    wp_enqueue_style('acme-clt-style');

    wp_register_style('acme-inss-style', $inss_css_url, [], $inss_css_ver);
    wp_enqueue_style('acme-inss-style');

    wp_register_script('acme-inss-form', $inss_js_url, [], $inss_js_ver, true);
    wp_enqueue_script('acme-inss-form');

    wp_localize_script('acme-inss-form', 'ACME_INSS', [
      'restStart'  => rest_url('acme/v1/api-inss'),
      'restStatus' => rest_url('acme/v1/api-inss-status'),
      'restNonce'  => wp_create_nonce('wp_rest'),
      'pdfUrl'     => admin_url('admin-ajax.php?action=acme_inss_pdf_request&_wpnonce=' . wp_create_nonce('acme_inss_pdf_nonce')),

    ]);
  }
});

/* ============================================================
 * 8) PDF HTML BUILDER (CLT) — ajustado ao formato response_json
 * ============================================================
 *
 * Agora geramos PDF baseado em:
 * - root.margem (campos visíveis)
 * - vinculos (para elegibilidade)
 * - simulacoes (se houver capturedResponse.body)
 *
 * Obs.: Mantemos assinatura simples para uso no endpoint.
 */

if (!function_exists('acme_clt_build_pdf_html')) {
  function acme_clt_build_pdf_html(array $row, array $root, array $vinculos, array $simulacoes = []): string
  {
    $fmt_money = function ($v): string {
      return 'R$ ' . number_format((float) ($v ?? 0), 2, ',', '.');
    };

    $fmt_date = function ($iso): string {
      if (empty($iso)) return '—';
      $ts = strtotime((string) $iso);
      if (!$ts) return '—';
      return date('d/m/Y', $ts);
    };

    $cpf_any = function ($cpf): string {
      $cpf = (string) $cpf;
      $digits = preg_replace('/\D+/', '', $cpf);
      if (strlen($digits) === 11) {
        return substr($digits, 0, 3) . '.' . substr($digits, 3, 3) . '.' . substr($digits, 6, 3) . '-' . substr($digits, 9, 2);
      }
      return $cpf ?: '—';
    };

    // elegível = existe vinculo.elegivel true
    $hasEligible = function (array $vinculos): bool {
      foreach ($vinculos as $v) {
        if (!is_array($v)) continue;
        if (array_key_exists('elegivel', $v) && ($v['elegivel'] === true || $v['elegivel'] === 1 || $v['elegivel'] === 'true')) return true;
        if (array_key_exists('Elegivel', $v) && ($v['Elegivel'] === true || $v['Elegivel'] === 1 || $v['Elegivel'] === 'true')) return true;
      }
      return false;
    };

    $margem = (isset($root['margem']) && is_array($root['margem'])) ? $root['margem'] : [];

    // Agora o server já manda o nome no root:
    // - elegível => nome real (Lemit)
    // - inelegível/erros => "Dados restringidos"
    $nome = (string) ($root['nome'] ?? '');
    $nome = trim($nome) !== '' ? $nome : 'Dados restringidos';
    //$cpf  = $cpf_any($root['cpf'] ?? ($row['cpf_masked'] ?? ($row['cpf'] ?? '—')));

    $cpf  = $cpf_any(
      $root['cpf_full']
        ?? $root['cpf']
        ?? $row['cpf']
        ?? $row['cpf_masked']
        ?? '—'
    );

    $valorMargemDisponivel = $fmt_money($margem['valorMargemDisponivel'] ?? 0);
    $valorMargemBase       = $fmt_money($margem['valorMargemBase'] ?? 0);
    $dataNascimento        = $fmt_date($margem['dataNascimento'] ?? null);
    $dataAdmissao          = $fmt_date($margem['dataAdmissao'] ?? null);

    $sexoRaw = strtoupper((string) ($margem['sexo'] ?? ''));
    $sexo = ($sexoRaw === 'F') ? 'Feminino' : (($sexoRaw === 'M') ? 'Masculino' : '—');

    $elegivel = $hasEligible($vinculos);
    $badge_bg = $elegivel ? '#e8fff0' : '#fff0f0';
    $badge_fg = $elegivel ? '#0a7a2f' : '#b00020';
    $badge_tx = $elegivel ? 'Elegível' : 'Não elegível';

    $dt_created = $row['created_at'] ?? current_time('mysql');
    $dt = esc_html(date('d/m/Y H:i:s', strtotime($dt_created ?: 'now')));

    // tabela de vínculos
    $vRows = '';
    foreach ((array) $vinculos as $v) {
      if (!is_array($v)) continue;
      $elg = (isset($v['elegivel']) ? $v['elegivel'] : ($v['Elegivel'] ?? false));
      $elg = ($elg === true || $elg === 1 || $elg === 'true') ? 'Sim' : 'Não';

      $registro = esc_html((string) ($v['numeroRegistro'] ?? ($v['NumeroRegistro'] ?? '—')));
      $doc      = esc_html((string) ($v['numeroDocumento'] ?? ($v['NumeroDocumento'] ?? '—')));
      $cnpj     = esc_html((string) ($v['numeroDocumentoEmpregador'] ?? ($v['NumeroDocumentoEmpregador'] ?? '—')));

      $vRows .= "<tr>
        <td>{$elg}</td>
        <td>{$registro}</td>
        <td>{$doc}</td>
        <td>{$cnpj}</td>
      </tr>";
    }
    if ($vRows === '') {
      $vRows = '<tr><td colspan="4">—</td></tr>';
    }

    // tabela de simulações (se existir)
    $sRows = '';
    if (!empty($simulacoes) && is_array($simulacoes)) {
      foreach ($simulacoes as $s) {
        if (!is_array($s)) continue;

        $snome  = esc_html((string) ($s['nome'] ?? '—'));
        $prazo  = esc_html((string) ($s['prazo'] ?? '—'));
        $taxa   = isset($s['taxaJuros']) ? esc_html((string) $s['taxaJuros']) . '%' : '—';
        $lib    = isset($s['valorLiberado']) ? esc_html($fmt_money($s['valorLiberado'])) : '—';
        $parc   = isset($s['valorParcela']) ? esc_html($fmt_money($s['valorParcela'])) : '—';

        $sRows .= "<tr>
          <td>{$snome}</td>
          <td>{$prazo}</td>
          <td>{$taxa}</td>
          <td>{$lib}</td>
          <td>{$parc}</td>
        </tr>";
      }
    }
    if ($sRows === '') {
      $sRows = '<tr><td colspan="5">—</td></tr>';
    }

    $html = '
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<style>
  body{ font-family: DejaVu Sans, Arial, sans-serif; font-size: 12px; color:#111; }
  .topbar{ background:#0b4ea2; height:34px; margin:-16px -16px 14px -16px; }
  .title{ text-align:center; font-size:22px; font-weight:700; letter-spacing:0.5px; margin:10px 0 6px; }
  .subtitle{ text-align:center; font-size:13px; margin:0 0 12px; color:#333; }
  .card{ border:1px solid #d6d6d6; border-radius:8px; margin:12px 0; }
  .card-h{ background:#e9ecef; padding:10px 12px; font-weight:700; }
  .card-b{ padding:12px; }
  .grid{ width:100%; }
  .grid td{ vertical-align:top; padding:4px 6px; }
  .label{ color:#333; font-weight:700; }
    .badge{
    display:inline-block;
    padding:4px 10px;
    border-radius:12px;
    background:' . $badge_bg . ';
    color:' . $badge_fg . ';
    font-weight:700;
    line-height:1.2;
    vertical-align:middle;
    position:relative;
    top:1px;
  }
  table.tbl{
    width:100%;
    border-collapse:collapse;
    table-layout:fixed;
    font-size:9.5px;
  }
  .tbl th,.tbl td{
    border:1px solid #222;
    padding:6px;
    vertical-align:top;
    white-space:normal;
    word-break:break-word;
    overflow-wrap:anywhere;
  }
  .tbl th{ background:#e9ecef; }
  .footer{ position:fixed; bottom:10px; left:0; right:0; font-size:10px; color:#333; }
  .footer .r{ float:right; }
</style>
</head>
<body>
  <div class="topbar"></div>

  <div class="title">CONSULTA CLT</div>
  <div class="subtitle">' . esc_html($nome) . ' • CPF: ' . esc_html($cpf) . '</div>

  <div class="card">
    <div class="card-h">Resumo</div>
    <div class="card-b">
      <table class="grid">
        <tr>
          <td width="60%">
            <div><span class="label">Nome:</span> ' . esc_html($nome) . '</div>
            <div><span class="label">CPF:</span> ' . esc_html($cpf) . '</div>
            <div><span class="label">Status consulta:</span> Completo</div>
            <div><span class="label">Elegibilidade:</span> <span class="badge">' . esc_html($badge_tx) . '</span></div>
          </td>
          <td width="40%">
            <div><span class="label">Margem disponível:</span> ' . esc_html($valorMargemDisponivel) . '</div>
            <div><span class="label">Margem base:</span> ' . esc_html($valorMargemBase) . '</div>
            <div><span class="label">Nascimento:</span> ' . esc_html($dataNascimento) . '</div>
            <div><span class="label">Sexo:</span> ' . esc_html($sexo) . '</div>
            <div><span class="label">Admissão:</span> ' . esc_html($dataAdmissao) . '</div>
          </td>
        </tr>
      </table>
    </div>
  </div>

  <div class="card">
    <div class="card-h">Vínculos retornados (' . count((array) $vinculos) . ')</div>
    <div class="card-b">
      <table class="tbl">
        <thead>
          <tr>
            <th style="width:10%;">Elegível</th>
            <th style="width:30%;">Registro</th>
            <th style="width:30%;">Documento</th>
            <th style="width:30%;">CNPJ Empregador</th>
          </tr>
        </thead>
        <tbody>' . $vRows . '</tbody>
      </table>
    </div>
  </div>

  <div class="card">
    <div class="card-h">Simulações (' . count((array) $simulacoes) . ')</div>
    <div class="card-b">
      <table class="tbl">
        <thead>
          <tr>
            <th style="width:44%;">Nome</th>
            <th style="width:12%;">Prazo</th>
            <th style="width:12%;">Taxa</th>
            <th style="width:16%;">Valor liberado</th>
            <th style="width:16%;">Parcela</th>
          </tr>
        </thead>
        <tbody>' . $sRows . '</tbody>
      </table>
    </div>
  </div>

  <div class="footer">
    <span>Gerado por Meu Consignado</span>
    <span class="r">' . $dt . '</span>
  </div>
</body>
</html>';

    return $html;
  }
}

/* ============================================================
 * 9) ENDPOINT AJAX — BAIXAR PDF (CLT)
 * ============================================================
 */

add_action('wp_ajax_acme_clt_pdf', function () {
  if (!is_user_logged_in()) wp_die('Você precisa estar logado.');

  $nonce = $_GET['_wpnonce'] ?? '';
  if (!$nonce || !wp_verify_nonce($nonce, 'acme_clt_pdf_nonce')) wp_die('Token inválido.');

  $result_id = (int) ($_GET['result_id'] ?? 0);
  if (!$result_id) wp_die('result_id obrigatório.');

  global $wpdb;
  $table = $wpdb->prefix . 'clt_results';

  $row = $wpdb->get_row(
    $wpdb->prepare("SELECT * FROM {$table} WHERE id=%d AND user_id=%d", $result_id, get_current_user_id()),
    ARRAY_A
  );

  if (!$row) wp_die('Registro não encontrado.');

  // Mantido por compatibilidade (se esse fluxo ainda existir na sua base antiga)
  $root = [];
  $vinculos = [];
  $simulacoes = [];

  $payload = json_decode($row['json_full'] ?? '{}', true);
  if (is_array($payload)) {
    // tenta algo parecido com o formato novo
    if (isset($payload['dados'][0]) && is_array($payload['dados'][0])) {
      $root = $payload['dados'][0];
      $vinculos = (isset($root['vinculos']) && is_array($root['vinculos'])) ? $root['vinculos'] : [];
      $body = $root['propostas']['capturedResponse']['body'] ?? null;
      if ($body) {
        $tmp = json_decode($body, true);
        $simulacoes = is_array($tmp) ? $tmp : [];
      }
    }
  }

  $html = acme_clt_build_pdf_html($row, $root, $vinculos, $simulacoes);
  acme_pdf_stream_html($html, 'consulta-clt-' . $result_id . '.pdf');
});

/**
 * ✅ PDF por request_id (fluxo novo, compatível com service_requests/response_json)
 * Regra mantida: só gera PDF se status=completed
 *
 * ✅ Ajustado: response_json => payload.dados[0] contém margem/vinculos/propostas
 */
add_action('wp_ajax_acme_clt_pdf_request', function () {
  if (!is_user_logged_in()) wp_die('Você precisa estar logado.');

  $nonce = $_GET['_wpnonce'] ?? '';
  if (!$nonce || !wp_verify_nonce($nonce, 'acme_clt_pdf_nonce')) wp_die('Token inválido.');

  $request_id = sanitize_text_field($_GET['request_id'] ?? '');
  if (!$request_id) wp_die('request_id obrigatório.');

  global $wpdb;
  $table = $wpdb->prefix . 'service_requests';

  $is_admin = current_user_can('manage_options');

  if ($is_admin) {
    $row = $wpdb->get_row(
      $wpdb->prepare(
        "SELECT * FROM {$table}
         WHERE request_id=%s OR provider_request_id=%s
         LIMIT 1",
        $request_id,
        $request_id
      ),
      ARRAY_A
    );
  } else {
    $row = $wpdb->get_row(
      $wpdb->prepare(
        "SELECT * FROM {$table}
         WHERE (request_id=%s OR provider_request_id=%s)
           AND user_id=%d
         LIMIT 1",
        $request_id,
        $request_id,
        get_current_user_id()
      ),
      ARRAY_A
    );
  }

  if (!$row) wp_die('Requisição não encontrada.');

  if (($row['status'] ?? '') !== 'completed') {
    wp_die('Consulta ainda não foi concluída.');
  }

  $payload = json_decode($row['response_json'] ?? '{}', true);
  if (!is_array($payload)) $payload = [];

  // ✅ formato do banco: payload.dados = [ { ... } ]
  $root = [];
  if (isset($payload['dados'][0]) && is_array($payload['dados'][0])) {
    $root = $payload['dados'][0];
  } elseif (isset($payload['dados']) && is_array($payload['dados']) && isset($payload['dados']['margem'])) {
    // fallback raro: dados como objeto
    $root = $payload['dados'];
  }

  $vinculos = (isset($root['vinculos']) && is_array($root['vinculos'])) ? $root['vinculos'] : [];

  // simulações (capturedResponse.body)
  $simulacoes = [];
  $body = $root['propostas']['capturedResponse']['body'] ?? null;
  if ($body) {
    $tmp = json_decode($body, true);
    if (is_array($tmp)) $simulacoes = $tmp;
  }

  $html = acme_clt_build_pdf_html($row, $root, $vinculos, $simulacoes);
  acme_pdf_stream_html($html, 'consulta-clt-' . $request_id . '.pdf');
});

add_action('wp_ajax_acme_inss_pdf_request', function () {
  if (!is_user_logged_in()) {
    wp_die('Você precisa estar logado.');
  }

  $nonce = $_GET['_wpnonce'] ?? '';
  if (!$nonce || !wp_verify_nonce($nonce, 'acme_inss_pdf_nonce')) {
    wp_die('Token inválido.');
  }

  $requestId = sanitize_text_field($_GET['request_id'] ?? '');
  if (!$requestId) {
    wp_die('request_id obrigatório.');
  }

  global $wpdb;
  $table = $wpdb->prefix . 'service_requests';

  $currentUserId = get_current_user_id();
  $currentUser = wp_get_current_user();

  $isAdmin = current_user_can('manage_options');
  $isMaster = function_exists('acme_user_has_role') && acme_user_has_role($currentUser, 'child');

  if ($isAdmin) {
    $row = $wpdb->get_row(
      $wpdb->prepare(
        "SELECT * FROM {$table}
         WHERE (request_id = %s OR provider_request_id = %s)
           AND service_slug = %s
         LIMIT 1",
        $requestId,
        $requestId,
        'inss'
      ),
      ARRAY_A
    );
  } elseif ($isMaster && function_exists('acme_get_credit_table_visible_user_ids')) {
    $visibleUserIds = acme_get_credit_table_visible_user_ids();
    $visibleUserIds = array_values(array_unique(array_map('intval', (array) $visibleUserIds)));

    if (empty($visibleUserIds)) {
      $visibleUserIds = [$currentUserId];
    }

    $placeholders = implode(',', array_fill(0, count($visibleUserIds), '%d'));

    $sql = "
      SELECT * FROM {$table}
      WHERE (request_id = %s OR provider_request_id = %s)
        AND service_slug = %s
        AND user_id IN ({$placeholders})
      LIMIT 1
    ";

    $params = array_merge([$requestId, $requestId, 'inss'], $visibleUserIds);

    $row = $wpdb->get_row(
      $wpdb->prepare($sql, $params),
      ARRAY_A
    );
  } else {
    $row = $wpdb->get_row(
      $wpdb->prepare(
        "SELECT * FROM {$table}
         WHERE (request_id = %s OR provider_request_id = %s)
           AND service_slug = %s
           AND user_id = %d
         LIMIT 1",
        $requestId,
        $requestId,
        'inss',
        $currentUserId
      ),
      ARRAY_A
    );
  }

  if (!$row) {
    wp_die('Requisição INSS não encontrada.');
  }

  if (($row['status'] ?? '') !== 'completed') {
    wp_die('Consulta ainda não foi concluída.');
  }

  $payload = json_decode($row['response_json'] ?? '{}', true);
  if (!is_array($payload)) {
    wp_die('response_json inválido.');
  }

  $dados = [];
  if (isset($payload['dados']) && is_array($payload['dados'])) {
    $dados = $payload;
  }

  if (empty($dados)) {
    wp_die('Dados da consulta INSS não encontrados.');
  }

  /*$html = acme_inss_build_pdf_html($row, $dados);
  acme_pdf_stream_html($html, 'consulta-inss-' . $requestId . '.pdf');*/

  try {
    require_once ACME_PLUGIN_DIR . 'includes/services/InssTcpdfService.php';

    $pdfService = new InssTcpdfService();
    $pdfService->outputPdf($row, $dados, 'consulta-inss-' . $requestId . '.pdf');
  } catch (\Throwable $e) {
    error_log('[ACME][INSS][PDF][TCPDF] ' . $e->getMessage());

    $html = acme_inss_build_pdf_html($row, $dados);
    acme_pdf_stream_html($html, 'consulta-inss-' . $requestId . '.pdf');
  }
});

/* ============================================================
 * 10) CRÉDITOS (LOTS) — Service lookup + balance + debug + consumo
 * ============================================================
 */

if (!function_exists('acme_get_service_id_by_slug')) {
  function acme_get_service_id_by_slug(string $service_slug): int
  {
    global $wpdb;
    $t = $wpdb->prefix . 'services';

    $id = (int) $wpdb->get_var($wpdb->prepare(
      "SELECT id FROM {$t} WHERE slug=%s LIMIT 1",
      $service_slug
    ));

    return $id ?: 0;
  }
}

if (!function_exists('acme_credit_balance_user')) {
  function acme_credit_balance_user(int $user_id, int $service_id): int
  {
    global $wpdb;
    $lotsT = $wpdb->prefix . 'credit_lots';
    $now = current_time('mysql');

    return (int) $wpdb->get_var($wpdb->prepare(
      "SELECT COALESCE(SUM(GREATEST(credits_total - credits_used, 0)), 0)
         FROM {$lotsT}
        WHERE owner_user_id=%d
          AND service_id=%d
          AND (expires_at IS NULL OR expires_at >= %s)",
      $user_id,
      $service_id,
      $now
    ));
  }
}

if (!function_exists('acme_user_has_credit')) {
  function acme_user_has_credit($user_id, $service_slug): bool
  {
    /*$service_id = acme_get_service_id_by_slug((string) $service_slug);
    if (!$service_id) return false;
    return acme_credit_balance_user((int) $user_id, (int) $service_id) > 0;
  }*/
    $user_id = (int) $user_id;

    // ✅ Admin não precisa de créditos (capability é mais seguro que role)
    if ($user_id > 0 && user_can($user_id, 'manage_options')) {
      return true;
    }

    $service_id = acme_get_service_id_by_slug((string) $service_slug);
    if (!$service_id) return false;

    return acme_credit_balance_user($user_id, (int) $service_id) > 0;
  }
}



if (!function_exists('acme_credit_check_debug')) {
  function acme_credit_check_debug(int $user_id, string $service_slug): array
  {
    global $wpdb;

    $servicesT = $wpdb->prefix . 'services';
    $lotsT = $wpdb->prefix . 'credit_lots';
    $now = current_time('mysql');

    $service_id = (int) $wpdb->get_var($wpdb->prepare(
      "SELECT id FROM {$servicesT} WHERE slug=%s LIMIT 1",
      $service_slug
    ));

    if (!$service_id) {
      return [
        'ok' => false,
        'step' => 'service_lookup',
        'reason' => 'service_slug_not_found',
        'user_id' => $user_id,
        'service_slug' => $service_slug,
        'service_id' => 0,
      ];
    }

    $rows = $wpdb->get_results($wpdb->prepare(
      "SELECT id, owner_user_id, service_id, status, expires_at, credits_total, credits_used, source, contract_id
         FROM {$lotsT}
        WHERE owner_user_id=%d AND service_id=%d
        ORDER BY id DESC",
      $user_id,
      $service_id
    ), ARRAY_A);

    $balance = (int) $wpdb->get_var($wpdb->prepare(
      "SELECT COALESCE(SUM(GREATEST(credits_total - credits_used, 0)), 0)
         FROM {$lotsT}
        WHERE owner_user_id=%d
          AND service_id=%d
          AND (expires_at IS NULL OR expires_at >= %s)",
      $user_id,
      $service_id,
      $now
    ));

    return [
      'ok' => ($balance > 0),
      'step' => 'lots_sum',
      'user_id' => $user_id,
      'service_slug' => $service_slug,
      'service_id' => $service_id,
      'now' => $now,
      'balance' => $balance,
      'wpdb_prefix' => $wpdb->prefix,
      'db_name' => DB_NAME,
      'lots_table' => $lotsT,
      'last_error' => $wpdb->last_error,
      'last_query' => $wpdb->last_query,
      'lots_found_all' => $rows,
    ];
  }
}

/* ============================================================
 * Débito assíncrono de créditos
 * ============================================================
 */

if (!function_exists('acme_enqueue_credit_debit')) {
  function acme_enqueue_credit_debit(int $user_id, string $service_slug, int $amount = 1, ?string $request_id = null): array
  {
    $user_id = (int) $user_id;
    $amount = max(1, (int) $amount);
    $request_id = $request_id !== null ? (string) $request_id : null;

    $args = [$user_id, $service_slug, $amount, $request_id];

    if (function_exists('wp_next_scheduled') && wp_next_scheduled('acme_async_credit_debit', $args)) {
      return ['ok' => true, 'scheduled' => false, 'reason' => 'already_scheduled'];
    }

    if (function_exists('wp_schedule_single_event')) {
      $ok = wp_schedule_single_event(time() + 1, 'acme_async_credit_debit', $args);
      return ['ok' => (bool) $ok, 'scheduled' => (bool) $ok];
    }

    return ['ok' => false, 'scheduled' => false, 'error' => 'scheduler_unavailable'];
  }
}

if (!function_exists('acme_async_credit_debit_handler')) {
  function acme_async_credit_debit_handler(int $user_id, string $service_slug, int $amount = 1, ?string $request_id = null): void
  {
    global $wpdb;

    $user_id = (int) $user_id;
    $amount = max(1, (int) $amount);
    $request_id = $request_id !== null ? (string) $request_id : null;

    if ($request_id) {
      $txT = $wpdb->prefix . 'credit_transactions';
      $exists = $wpdb->get_var($wpdb->prepare(
        "SELECT id FROM {$txT} WHERE request_id=%s AND type='debit' AND status='success' LIMIT 1",
        $request_id
      ));
      if ($exists) return;
    }

    if (function_exists('acme_consume_credit')) {
      acme_consume_credit($user_id, $service_slug, $amount, $request_id);
    }
  }
}

add_action('acme_async_credit_debit', 'acme_async_credit_debit_handler', 10, 4);

if (!function_exists('acme_consume_credit')) {
  function acme_consume_credit($user_id, $service_slug, $amount = 1, $request_id = null, $service_name = null)
  {
    global $wpdb;

    $user_id = (int) $user_id;
    $amount = max(1, (int) $amount);

    // ✅ Admin não consome créditos
    if ($user_id > 0 && user_can($user_id, 'manage_options')) {
      return [
        'ok' => true,
        'skipped' => true,
        'reason' => 'admin_exempt',
      ];
    }

    $service_id = acme_get_service_id_by_slug((string) $service_slug);
    if (!$service_id) return ['ok' => false, 'error' => 'Serviço inválido (slug não existe em wp_services)'];

    $lotsT = $wpdb->prefix . 'credit_lots';
    $txT   = $wpdb->prefix . 'credit_transactions';
    $ctT   = $wpdb->prefix . 'credit_contracts';
    $now   = current_time('mysql');

    // Idempotência de débito:
    // Se o request_id já foi debitado com sucesso, não debita novamente.
    // (Usado para regra: 1 débito por CPF por usuário por dia.)
    if (!empty($request_id)) {
      $already = $wpdb->get_var($wpdb->prepare(
        "SELECT id FROM {$txT} WHERE request_id=%s AND type='debit' AND status='success' LIMIT 1",
        (string) $request_id
      ));
      if ($already) {
        return [
          'ok' => true,
          'skipped' => true,
          'reason' => 'already_debited',
          'request_id' => (string) $request_id
        ];
      }
    }

    $wpdb->query('START TRANSACTION');

    try {
      $lot = $wpdb->get_row($wpdb->prepare(
        "SELECT id, contract_id, credits_total, credits_used, expires_at
           FROM {$lotsT}
          WHERE owner_user_id=%d
            AND service_id=%d
            AND (expires_at IS NULL OR expires_at >= %s)
            AND credits_total > credits_used
          ORDER BY (expires_at IS NULL) ASC, expires_at ASC, id ASC
          LIMIT 1
          FOR UPDATE",
        $user_id,
        $service_id,
        $now
      ), ARRAY_A);

      if (!$lot) {
        $wpdb->query('ROLLBACK');
        return ['ok' => false, 'error' => 'Sem créditos (lotes)'];
      }

      $before_total = (int) $lot['credits_total'];
      $before_used  = (int) $lot['credits_used'];
      $before_avail = max($before_total - $before_used, 0);

      if ($before_avail < $amount) {
        $wpdb->query('ROLLBACK');
        return ['ok' => false, 'error' => 'Saldo insuficiente no lote selecionado'];
      }

      $after_used  = $before_used + $amount;
      $after_avail = max($before_total - $after_used, 0);

      $ok = $wpdb->update(
        $lotsT,
        ['credits_used' => $after_used, 'updated_at' => $now],
        ['id' => (int) $lot['id']]
      );
      if ($ok === false) throw new Exception('Falha ao atualizar lote: ' . $wpdb->last_error);

      $contract_id = !empty($lot['contract_id']) ? (int) $lot['contract_id'] : 0;

      if ($contract_id > 0) {
        $c = $wpdb->get_row($wpdb->prepare(
          "SELECT id, credits_total, credits_used, valid_until
             FROM {$ctT}
            WHERE id=%d
            LIMIT 1
            FOR UPDATE",
          $contract_id
        ), ARRAY_A);

        if ($c) {
          if (!empty($c['valid_until']) && strtotime($c['valid_until']) < time()) {
            throw new Exception('Contrato expirado (valid_until)');
          }

          $c_used  = (int) $c['credits_used'];
          $c_total = (int) $c['credits_total'];

          if (($c_total - $c_used) < $amount) {
            throw new Exception('Contrato sem saldo suficiente');
          }

          $okc = $wpdb->update(
            $ctT,
            ['credits_used' => ($c_used + $amount), 'updated_at' => $now],
            ['id' => $contract_id]
          );
          if ($okc === false) throw new Exception('Falha ao atualizar contrato: ' . $wpdb->last_error);
        }
      }

      $total_before = (int) ($lot['credits_total'] ?? 0);
      $used_before  = (int) ($lot['credits_used'] ?? 0);

      $total_after = $total_before;
      $used_after  = $used_before + (int) $amount;

      $meta_arr  = ['lot_id' => (int) ($lot['id'] ?? 0)];
      $meta_json = wp_json_encode($meta_arr);

      $ok = $wpdb->insert($txT, [
        'user_id' => (int) $user_id,
        'service_id' => (int) $service_id,
        'service_slug' => $service_slug ?? null,
        'service_name' => $service_name ?? null,

        'type' => 'debit',
        'credits' => (int) $amount,
        'status' => 'success',
        'attempts' => 1,

        'origin' => 'consumption',


        'request_id' => $request_id ?? null,
        'actor_user_id' => (int) $user_id,

        'notes' => 'Uso de crédito em ' . $service_slug,
        'meta' => $meta_json,
        'created_at' => $now,

        'wallet_total_before' => $total_before,
        'wallet_used_before' => $used_before,
        'wallet_total_after' => $total_after,
        'wallet_used_after' => $used_after,
      ]);

      if ($ok === false) {
        $wpdb->query('ROLLBACK');
        return new WP_Error('tx_insert_failed', $wpdb->last_error ?: 'Falha ao inserir em wp_credit_transactions');
      }

      $wpdb->query('COMMIT');

      return [
        'ok' => true,
        'wallet_id' => (int) $lot['id'],
        'contract_id' => $contract_id ?: null,
        'before' => $before_avail,
        'after' => $after_avail,
      ];
    } catch (Throwable $e) {
      $wpdb->query('ROLLBACK');
      return ['ok' => false, 'error' => $e->getMessage()];
    }
  }
}

/* ============================================================
 * API LOGS TABLE (se estiver usando)
 * ============================================================
 */

function acme_api_logs_activate()
{
  global $wpdb;
  require_once ABSPATH . 'wp-admin/includes/upgrade.php';
  $charset = $wpdb->get_charset_collate();

  $table = $wpdb->prefix . 'api_logs';

  $sql = "CREATE TABLE {$table} (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NULL,
    service_slug VARCHAR(100) NULL,
    provider VARCHAR(50) NULL,
    status VARCHAR(20) NULL,
    request_id VARCHAR(64) NULL,
    message TEXT NULL,
    payload LONGTEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_user (user_id),
    KEY idx_service (service_slug),
    KEY idx_request (request_id),
    KEY idx_created (created_at)
  ) {$charset};";

  dbDelta($sql);
}

// DEV ONLY (localhost): libera REST do namespace acme/v1 para todos os métodos
add_filter('rest_authentication_errors', function ($result) {
  $uri = $_SERVER['REQUEST_URI'] ?? '';
  if (stripos($uri, '/wp-json/acme/v1/') !== false) return null;
  return $result;
}, PHP_INT_MAX);

add_filter('rest_pre_dispatch', function ($result, $server, $request) {
  $route = method_exists($request, 'get_route') ? $request->get_route() : '';
  if (strpos($route, '/acme/v1/') !== false) return null;
  return $result;
}, PHP_INT_MAX, 3);

/* ============================================================
 * CSS (painéis)
 * ============================================================
 */

if (!function_exists('acme_ui_panel_css')) {
  function acme_ui_panel_css(): string
  {
    static $done = false;
    if ($done) return '';
    $done = true;

    return '<style>
.acme-panel{max-width:auto;margin:18px auto;background:#fff;border:1px solid #e5e7eb;border-radius:14px;box-shadow:0 8px 22px rgba(0,0,0,.06);overflow:hidden;font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial}
.acme-panel-h{display:flex;justify-content:space-between;align-items:center;padding:14px 16px;border-bottom:1px solid #f1f5f9}
.acme-panel-title{font-weight:900;font-size:16px;color:#0f172a}
.acme-panel-sub{font-size:13px;color:#64748b}
.acme-btn{display:inline-block;padding:8px 12px;border-radius:10px;border:1px solid #e2e8f0;background:#f8fafc;text-decoration:none;font-weight:800;color:#0f172a}
.acme-btn:hover{background:#eef2ff}
.acme-table{width:100%;border-collapse:collapse;font-size:13px}
.acme-table th,.acme-table td{border-top:1px solid #f1f5f9;padding:10px 12px;text-align:left;vertical-align:top}
.acme-table th{background:#f8fafc;color:#334155;font-weight:900}
.acme-badge{display:inline-block;padding:4px 10px;border-radius:999px;font-weight:900;font-size:12px;border:1px solid transparent}
.acme-badge-pending{background:#fff7ed;color:#9a3412;border-color:#fed7aa}
.acme-badge-completed{background:#ecfdf5;color:#047857;border-color:#a7f3d0}
.acme-badge-failed{background:#fef2f2;color:#b91c1c;border-color:#fecaca}
.acme-mono{font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,monospace;font-size:12px;color:#334155}
.acme-muted{color:#64748b}

/* 🔽 ADICIONE ISSO AQUI */
.acme-col-error{
  width:220px;
  max-width:220px;
  min-width:220px;
  white-space:normal;
  word-break:break-word;
  overflow-wrap:break-word;
}

.acme-col-error .acme-error-text{
  display:-webkit-box;
  -webkit-line-clamp:3;
  -webkit-box-orient:vertical;
  overflow:hidden;
  line-height:1.45;
}

</style>';
  }
}

if (!function_exists('acme_get_grandchildren_of_child')) {
  function acme_get_grandchildren_of_child(int $child_id): array
  {
    global $wpdb;

    $linksTable = function_exists('acme_table_links')
      ? acme_table_links()
      : ($wpdb->prefix . 'account_links');

    $grandchildrenIds = $wpdb->get_col($wpdb->prepare(
      "SELECT child_user_id
       FROM {$linksTable}
       WHERE parent_user_id = %d
         AND depth = 2",
      $child_id
    ));

    return array_values(array_unique(array_map('intval', (array) $grandchildrenIds)));
  }
}

#========================================================
if (!function_exists('acme_credits_table_resolve_context_user_id')) {
  /**
   * Resolve o usuário-alvo conforme contexto da tela.
   *
   * Regras:
   * - my-profile => sempre usuário logado
   * - view-user / edit-user => usa user_id da URL
   * - fallback => usuário logado
   */
  function acme_credits_table_resolve_context_user_id(): int
  {
    if (!is_user_logged_in()) {
      return 0;
    }

    $currentUserId = get_current_user_id();
    $requestUri = isset($_SERVER['REQUEST_URI']) ? (string) wp_unslash($_SERVER['REQUEST_URI']) : '';

    $isMyProfile = (strpos($requestUri, '/my-profile') !== false);
    $isViewUser  = (strpos($requestUri, '/view-user') !== false);
    $isEditUser  = (strpos($requestUri, '/edit-user') !== false);

    if ($isMyProfile) {
      return $currentUserId;
    }

    if ($isViewUser || $isEditUser) {
      $targetUserId = isset($_GET['user_id']) ? (int) $_GET['user_id'] : 0;
      return $targetUserId > 0 ? $targetUserId : $currentUserId;
    }

    return $currentUserId;
  }
}

if (!function_exists('acme_can_view_credit_table_for_user')) {
  /**
   * Valida se o usuário logado pode visualizar dados do usuário-alvo.
   *
   * Admin => qualquer usuário
   * Master => ele mesmo + sub-logins dele
   * Sub-login => somente ele mesmo
   */
  function acme_can_view_credit_table_for_user(int $targetUserId): bool
  {
    if (!is_user_logged_in() || $targetUserId <= 0) {
      return false;
    }

    $currentUserId = get_current_user_id();
    $currentUser = wp_get_current_user();

    if (current_user_can('manage_options')) {
      return true;
    }

    if ((int) $currentUserId === (int) $targetUserId) {
      return true;
    }

    if (acme_user_has_role($currentUser, 'child')) {
      $grandchildrenIds = function_exists('acme_get_grandchildren_of_child')
        ? acme_get_grandchildren_of_child($currentUserId)
        : [];

      return in_array($targetUserId, array_map('intval', $grandchildrenIds), true);
    }

    return false;
  }
}

if (!function_exists('acme_get_credit_table_visible_user_ids')) {
  /**
   * Retorna os usuários que entram no escopo da tabela.
   *
   * Admin:
   * - se estiver em view-user/edit-user => só o target da URL
   * - se estiver em my-profile => só ele mesmo
   * - demais contextos => todos os child + grandchild + próprio alvo quando aplicável
   *
   * Master:
   * - my-profile => só ele mesmo
   * - view-user/edit-user => target permitido
   * - demais => ele mesmo + sub-logins dele
   *
   * Sub-login:
   * - sempre só ele mesmo
   */
  function acme_get_credit_table_visible_user_ids(int $resolvedTargetUserId = 0): array
  {
    global $wpdb;

    if (!is_user_logged_in()) {
      return [];
    }

    $currentUserId = get_current_user_id();
    $currentUser = wp_get_current_user();
    $requestUri = isset($_SERVER['REQUEST_URI']) ? (string) wp_unslash($_SERVER['REQUEST_URI']) : '';

    $isMyProfile = (strpos($requestUri, '/my-profile') !== false);
    $isViewUser  = (strpos($requestUri, '/view-user') !== false);
    $isEditUser  = (strpos($requestUri, '/edit-user') !== false);

    $targetUserId = $resolvedTargetUserId > 0 ? $resolvedTargetUserId : $currentUserId;

    // my-profile sempre força self
    if ($isMyProfile) {
      return [$currentUserId];
    }

    // Admin
    if (current_user_can('manage_options')) {
      if (($isViewUser || $isEditUser) && $targetUserId > 0) {
        return [$targetUserId];
      }

      $userIds = get_users([
        'fields'   => 'ID',
        'role__in' => ['child', 'grandchild'],
        'number'   => 2000,
      ]);

      $userIds = array_map('intval', (array) $userIds);

      if ($targetUserId > 0 && !in_array($targetUserId, $userIds, true)) {
        $userIds[] = $targetUserId;
      }

      return array_values(array_unique($userIds));
    }

    // Master
    if (acme_user_has_role($currentUser, 'child')) {
      if (($isViewUser || $isEditUser) && $targetUserId > 0) {
        return acme_can_view_credit_table_for_user($targetUserId) ? [$targetUserId] : [];
      }

      $userIds = [$currentUserId];

      if (function_exists('acme_get_grandchildren_of_child')) {
        $userIds = array_merge($userIds, acme_get_grandchildren_of_child($currentUserId));
      }

      return array_values(array_unique(array_map('intval', $userIds)));
    }

    // Sub-login
    return [$currentUserId];
  }
}

if (!function_exists('acme_get_credit_table_rows')) {
  /**
   * Lista detalhada:
   * usuário | serviço | créditos disponíveis | validade
   *
   * Agrupa por validade para não misturar lotes com vencimentos diferentes.
   */
  function acme_get_credit_table_rows(array $userIds): array
  {
    global $wpdb;

    $userIds = array_values(array_filter(array_map('intval', $userIds)));
    if (empty($userIds)) {
      return [];
    }

    $lotsTable = function_exists('acme_table_credit_lots')
      ? acme_table_credit_lots()
      : ($wpdb->prefix . 'credit_lots');

    $servicesTable = function_exists('acme_table_services')
      ? acme_table_services()
      : ($wpdb->prefix . 'services');

    $usersTable = $wpdb->users;
    $nowMysql = current_time('mysql');

    $placeholders = implode(',', array_fill(0, count($userIds), '%d'));

    $sql = "
      SELECT
        l.owner_user_id AS user_id,
        u.display_name,
        u.user_email,
        s.id AS service_id,
        s.slug AS service_slug,
        s.name AS service_name,
        l.expires_at,
        COALESCE(SUM(GREATEST(l.credits_total - l.credits_used, 0)), 0) AS available_credits
      FROM {$lotsTable} l
      INNER JOIN {$usersTable} u
        ON u.ID = l.owner_user_id
      INNER JOIN {$servicesTable} s
        ON s.id = l.service_id
      WHERE l.owner_user_id IN ({$placeholders})
        AND (l.expires_at IS NULL OR l.expires_at >= %s)
      GROUP BY
        l.owner_user_id,
        u.display_name,
        u.user_email,
        s.id,
        s.slug,
        s.name,
        l.expires_at
      HAVING available_credits > 0
      ORDER BY
        u.display_name ASC,
        s.name ASC,
        l.expires_at ASC
    ";

    $prepared = $wpdb->prepare($sql, array_merge($userIds, [$nowMysql]));
    $rows = $wpdb->get_results($prepared, ARRAY_A);

    return is_array($rows) ? $rows : [];
  }
}

if (!function_exists('acme_get_credit_table_service_totals')) {
  /**
   * Resumo por serviço para Admin e Master.
   */
  function acme_get_credit_table_service_totals(array $userIds): array
  {
    global $wpdb;

    $userIds = array_values(array_filter(array_map('intval', $userIds)));
    if (empty($userIds)) {
      return [];
    }

    $lotsTable = function_exists('acme_table_credit_lots')
      ? acme_table_credit_lots()
      : ($wpdb->prefix . 'credit_lots');

    $servicesTable = function_exists('acme_table_services')
      ? acme_table_services()
      : ($wpdb->prefix . 'services');

    $nowMysql = current_time('mysql');
    $placeholders = implode(',', array_fill(0, count($userIds), '%d'));

    $sql = "
      SELECT
        s.id AS service_id,
        s.slug AS service_slug,
        s.name AS service_name,
        COALESCE(SUM(GREATEST(l.credits_total - l.credits_used, 0)), 0) AS total_available
      FROM {$lotsTable} l
      INNER JOIN {$servicesTable} s
        ON s.id = l.service_id
      WHERE l.owner_user_id IN ({$placeholders})
        AND (l.expires_at IS NULL OR l.expires_at >= %s)
      GROUP BY s.id, s.slug, s.name
      HAVING total_available > 0
      ORDER BY s.name ASC
    ";

    $prepared = $wpdb->prepare($sql, array_merge($userIds, [$nowMysql]));
    $rows = $wpdb->get_results($prepared, ARRAY_A);

    return is_array($rows) ? $rows : [];
  }
}


/**
 * Novo serviço INSS
 */


//Gerar o PDF báásico
if (!function_exists('acme_inss_build_pdf_html')) {
  function acme_inss_build_pdf_html(array $row, array $dados): string
  {
    $esc = static function ($value): string {
      if (is_array($value) || is_object($value)) {
        return '—';
      }

      $value = trim((string) $value);
      return esc_html($value !== '' ? $value : '—');
    };

    $onlyDigits = static function ($value): string {
      return preg_replace('/\D+/', '', (string) $value);
    };

    $formatCpf = static function ($value) use ($onlyDigits): string {
      $digits = $onlyDigits($value);
      if (strlen($digits) !== 11) {
        return trim((string) $value) !== '' ? esc_html((string) $value) : '—';
      }

      return substr($digits, 0, 3) . '.' . substr($digits, 3, 3) . '.' . substr($digits, 6, 3) . '-' . substr($digits, 9, 2);
    };

    $formatBenefit = static function ($value) use ($onlyDigits): string {
      $digits = $onlyDigits($value);
      if (strlen($digits) !== 10) {
        return trim((string) $value) !== '' ? esc_html((string) $value) : '—';
      }

      return substr($digits, 0, 3) . '.' . substr($digits, 3, 3) . '.' . substr($digits, 6, 3) . '-' . substr($digits, 9, 1);
    };

    $formatMoney = static function ($value): string {
      if ($value === null || $value === '') {
        return 'R$0,00';
      }

      if (is_string($value)) {
        $normalized = str_replace(['R$', '.', ','], ['', '', '.'], trim($value));
        if (is_numeric($normalized)) {
          $value = (float) $normalized;
        }
      }

      if (is_numeric($value)) {
        return 'R$' . number_format((float) $value, 2, ',', '.');
      }

      return esc_html((string) $value);
    };

    $formatDate = static function ($value): string {
      if (empty($value)) {
        return '—';
      }

      $timestamp = strtotime((string) $value);
      if (!$timestamp) {
        return esc_html((string) $value);
      }

      return date_i18n('d/m/Y', $timestamp);
    };

    $formatMonthYear = static function ($value): string {
      if (empty($value)) {
        return '—';
      }

      $timestamp = strtotime((string) $value);
      if ($timestamp) {
        return date_i18n('m/Y', $timestamp);
      }

      if (preg_match('/^\d{2}\/\d{4}$/', (string) $value)) {
        return esc_html((string) $value);
      }

      return esc_html((string) $value);
    };

    $formatDateTime = static function ($value): string {
      if (empty($value)) {
        return date_i18n('d/m/Y H:i:s');
      }

      $timestamp = strtotime((string) $value);
      if (!$timestamp) {
        return date_i18n('d/m/Y H:i:s');
      }

      return date_i18n('d/m/Y H:i:s', $timestamp);
    };

    $boolText = static function ($value, string $yes = 'Possui', string $no = 'Não possui'): string {
      if ($value === true || $value === 1 || $value === '1') {
        return $yes;
      }

      if ($value === false || $value === 0 || $value === '0') {
        return $no;
      }

      return $no;
    };

    $bankDescription = static function ($bankValue): string {
      if (is_array($bankValue)) {
        $description = trim((string) ($bankValue['descricao'] ?? ''));
        $code = trim((string) ($bankValue['codigo'] ?? ''));
        if ($description !== '') {
          return $description;
        }
        if ($code !== '') {
          return $code;
        }
        return '—';
      }

      $bankValue = trim((string) $bankValue);
      return $bankValue !== '' ? $bankValue : '—';
    };

    $get = static function (array $source, array $keys, $default = '—') {
      foreach ($keys as $key) {
        if (array_key_exists($key, $source) && $source[$key] !== null && $source[$key] !== '') {
          return $source[$key];
        }
      }
      return $default;
    };

    $sumByStatus = static function (array $items, string $targetStatus): int {
      $count = 0;
      foreach ($items as $item) {
        if (!is_array($item)) {
          continue;
        }
        $status = strtolower(trim((string) ($item['situacao'] ?? '')));
        if ($status === strtolower($targetStatus)) {
          $count++;
        }
      }
      return $count;
    };

    $qrImagePath = plugin_dir_path(dirname(__FILE__, 2)) . 'assets/img/inss-qr-fixo.png';
    $qrImageSrc = '';
    if (file_exists($qrImagePath) && is_readable($qrImagePath)) {
      $qrMime = function_exists('mime_content_type') ? mime_content_type($qrImagePath) : 'image/png';
      $qrImageSrc = 'data:' . $qrMime . ';base64,' . base64_encode((string) file_get_contents($qrImagePath));
    }

    $authText = defined('ACME_INSS_AUTH_TEXT')
      ? (string) ACME_INSS_AUTH_TEXT
      : 'Consulte a autenticidade com o código informado no QR Code.';

    $generatedAt = $formatDateTime($row['completed_at'] ?? $row['updated_at'] ?? $row['created_at'] ?? current_time('mysql'));

    $beneficioNumero = $get($dados, ['beneficio', 'numeroBeneficio', 'nb'], $row['cpf_masked'] ?? '');
    $beneficioFormatado = $formatBenefit($beneficioNumero);

    $nome = $esc($get($dados, ['nome', 'nomeBeneficiario'], '—'));
    $especieDescricao = $esc((string) ($dados['especie']['descricao'] ?? $dados['beneficioDescricao'] ?? '—'));
    $situacao = $esc($get($dados, ['situacao'], 'ATIVO'));

    $bancoPagamento = $esc($bankDescription($dados['banco'] ?? $dados['bancoPagamento'] ?? '—'));
    $meioPagamento = $esc($get($dados, ['meioPagamento', 'formaPagamento'], '—'));
    $agencia = $esc($get($dados, ['agencia', 'codigoAgencia'], '—'));
    $conta = $esc($get($dados, ['conta', 'contaCorrente'], '—'));

    $possuiProcurador = $esc($boolText($get($dados, ['possuiProcurador'], false), 'Possui procurador', 'Não possui procurador'));
    $possuiRepresentante = $esc($boolText($get($dados, ['possuiRepresentante', 'possuiRepresentanteLegal'], false), 'Possui representante legal', 'Não possui representante legal'));
    $pensaoAlimenticia = $esc($boolText($get($dados, ['pensaoAlimenticia'], false), 'Com pensão alimentícia', 'Pensão alimentícia'));
    $elegivelEmprestimoBool = $get($dados, ['elegivelEmprestimo'], true);
    $elegivelEmprestimo = $esc(($elegivelEmprestimoBool === true || $elegivelEmprestimoBool === 1 || $elegivelEmprestimoBool === '1') ? 'Elegível para empréstimos' : 'Não elegível para empréstimos');

    $contratosEmprestimo = is_array($dados['contratosEmprestimo'] ?? null) ? $dados['contratosEmprestimo'] : [];
    $contratosRmc = is_array($dados['contratosRMC'] ?? null) ? $dados['contratosRMC'] : [];
    $contratosRcc = is_array($dados['contratosRCC'] ?? null) ? $dados['contratosRCC'] : [];

    $ativosEmprestimo = $sumByStatus($contratosEmprestimo, 'ativo');
    $suspensosEmprestimo = $sumByStatus($contratosEmprestimo, 'suspenso');

    $ativosCartao = $sumByStatus($contratosRmc, 'ativo') + $sumByStatus($contratosRcc, 'ativo');
    $suspensosCartao = $sumByStatus($contratosRmc, 'suspenso') + $sumByStatus($contratosRcc, 'suspenso');

    $ativosTotal = $ativosEmprestimo + $ativosCartao;
    $suspensosTotal = $suspensosEmprestimo + $suspensosCartao;

    $baseCalculo = $get($dados, ['valorBase', 'margemBase', 'baseCalculo'], 0);
    $margemConsignavel = $get($dados, ['margemConsignavel'], 0);
    $margemUtilizadaEmprestimo = $get($dados, ['margemUtilizadaEmprestimo'], 0);
    $margemDisponivelEmprestimo = $get($dados, ['margemDisponivelEmprestimo'], 0);
    $margemRmc = $get($dados, ['margemRmc', 'margemUtilizadaRmc'], 0);
    $margemRcc = $get($dados, ['margemRcc', 'margemUtilizadaRcc'], 0);

    $maxComprometimento = is_numeric($baseCalculo) ? ((float) $baseCalculo * 0.45) : 0;
    $totalComprometido = (float) (is_numeric($margemUtilizadaEmprestimo) ? $margemUtilizadaEmprestimo : 0)
      + (float) (is_numeric($margemRmc) ? $margemRmc : 0)
      + (float) (is_numeric($margemRcc) ? $margemRcc : 0);

    $margemDisponivelEmprestimoValor = is_numeric($margemDisponivelEmprestimo)
      ? (float) $margemDisponivelEmprestimo
      : 0;

    $margemDisponivelRmc = is_numeric($margemRmc) ? max((((float) $baseCalculo) * 0.05) - (float) $margemRmc, 0) : 0;
    $margemDisponivelRcc = is_numeric($margemRcc) ? max((((float) $baseCalculo) * 0.05) - (float) $margemRcc, 0) : 0;

    $renderContractRows = static function (array $items) use ($esc, $formatDate, $formatMonthYear, $formatMoney, $bankDescription): string {
      $html = '';

      foreach ($items as $item) {
        if (!is_array($item)) {
          continue;
        }

        $numeroContrato = $esc($item['contrato'] ?? $item['numeroContrato'] ?? '—');
        $banco = $esc($bankDescription($item['banco'] ?? '—'));
        $situacao = $esc($item['situacao'] ?? '—');
        $origem = $esc($item['origemAverbacao'] ?? '');
        $dataInclusao = $formatDate($item['dataInclusao'] ?? $item['dataInicio'] ?? '');
        $competenciaInicio = $formatMonthYear($item['competenciaInicio'] ?? $item['dataInicio'] ?? '');
        $competenciaFim = $formatMonthYear($item['competenciaFim'] ?? $item['dataFim'] ?? '');
        $qtdParcelas = $esc($item['quantidadeParcelas'] ?? '—');
        $parcela = $formatMoney($item['valorParcela'] ?? 0);
        $valorEmprestado = $formatMoney($item['valorEmprestado'] ?? 0);
        $valorLiberado = $formatMoney($item['valorLiberado'] ?? 0);
        $iof = $formatMoney($item['iof'] ?? 0);
        $cetMensal = $esc($item['cetMensal'] ?? '');
        $cetAnual = $esc($item['cetAnual'] ?? '');
        $taxaMensal = $esc($item['taxaMensal'] ?? '');
        $taxaAnual = $esc($item['taxaAnual'] ?? '');

        $html .= '
          <tr>
            <td>' . $numeroContrato . '</td>
            <td>' . $banco . '</td>
            <td>' . $situacao . '</td>
            <td>' . $origem . '</td>
            <td>' . $dataInclusao . '</td>
            <td>' . $competenciaInicio . '</td>
            <td>' . $competenciaFim . '</td>
            <td>' . $qtdParcelas . '</td>
            <td>' . $parcela . '</td>
            <td>' . $valorEmprestado . '</td>
            <td>' . $valorLiberado . '</td>
            <td>' . $iof . '</td>
            <td>' . $cetMensal . '</td>
            <td>' . $cetAnual . '</td>
            <td>' . $taxaMensal . '</td>
            <td>' . $taxaAnual . '</td>
            <td></td>
            <td></td>
            <td></td>
            <td></td>
          </tr>';
      }

      if ($html === '') {
        $html = '
          <tr>
            <td colspan="20" style="text-align:center;">Nenhum contrato encontrado.</td>
          </tr>';
      }

      return $html;
    };

    $renderCardRows = static function (array $items) use ($esc, $formatDate, $formatMoney, $bankDescription): string {
      $html = '';

      foreach ($items as $item) {
        if (!is_array($item)) {
          continue;
        }

        $numeroContrato = $esc($item['contrato'] ?? $item['numeroContrato'] ?? '—');
        $banco = $esc($bankDescription($item['banco'] ?? '—'));
        $situacao = $esc($item['situacao'] ?? '—');
        $origem = $esc($item['origemAverbacao'] ?? '');
        $dataInclusao = $formatDate($item['dataInclusao'] ?? '');
        $limite = $formatMoney($item['limite'] ?? $item['limiteCartao'] ?? 0);
        $reservado = $formatMoney($item['valorReservado'] ?? $item['reservado'] ?? 0);

        $html .= '
          <tr>
            <td>' . $numeroContrato . '</td>
            <td>' . $banco . '</td>
            <td>' . $situacao . '</td>
            <td>' . $origem . '</td>
            <td>' . $dataInclusao . '</td>
            <td>' . $limite . '</td>
            <td>' . $reservado . '</td>
            <td></td>
            <td></td>
            <td></td>
            <td></td>
          </tr>';
      }

      if ($html === '') {
        $html = '
          <tr>
            <td colspan="11" style="text-align:center;">Nenhum contrato encontrado.</td>
          </tr>';
      }

      return $html;
    };

    $emprestimoRowsHtml = $renderContractRows($contratosEmprestimo);
    $rmcRowsHtml = $renderCardRows($contratosRmc);
    $rccRowsHtml = $renderCardRows($contratosRcc);

    $page1QrBlock = $qrImageSrc !== ''
      ? '<div class="qr-box"><img src="' . esc_attr($qrImageSrc) . '" alt="QR Code"></div>'
      : '<div class="qr-box qr-placeholder">QR</div>';

    return '<!doctype html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8">
  <title>Histórico de Empréstimo Consignado</title>
  <style>
    @page {
      margin: 14mm 10mm 14mm 10mm;
    }

    body {
      font-family: DejaVu Sans, sans-serif;
      color: #222;
      font-size: 10px;
      margin: 0;
      padding: 0;
    }

    .page {
      position: relative;
      min-height: 267mm;
      page-break-after: always;
    }

    .page.last {
      page-break-after: auto;
    }

    .top-band {
      width: 100%;
      height: 16px;
      background: #3b3b3b;
      position: relative;
      margin-bottom: 12px;
    }

    .top-band .blue {
      width: 38%;
      height: 16px;
      background: #005db8;
      margin: 0 auto;
      color: #fff;
      text-align: center;
      font-size: 8px;
      line-height: 16px;
      font-weight: bold;
    }

    .doc-title {
      font-size: 16px;
      font-weight: bold;
      margin: 0 0 8px 0;
    }

    .subtitle {
      font-size: 11px;
      font-weight: bold;
      margin: 0 0 8px 0;
    }

    .muted {
      color: #666;
    }

    table {
      width: 100%;
      border-collapse: collapse;
    }

    .bordered td,
    .bordered th {
      border: 1px solid #444;
      padding: 4px 5px;
      vertical-align: middle;
    }

    .bordered th {
      background: #e9ecef;
      font-weight: bold;
      text-align: center;
    }

    .header-table td {
      border: none;
      padding: 1px 0;
      vertical-align: top;
    }

    .header-table .left-col {
      width: 58%;
      padding-right: 8px;
    }

    .header-table .right-col {
      width: 42%;
      padding-left: 8px;
    }

    .field-label {
      font-weight: bold;
    }

    .gray-strip {
      background: #e9ecef;
      border: 1px solid #777;
      padding: 8px 10px;
      font-size: 13px;
      font-weight: bold;
      margin-top: 6px;
      margin-bottom: 8px;
    }

    .section-title {
      font-size: 12px;
      font-weight: bold;
      margin: 0 0 8px 0;
    }

    .small {
      font-size: 9px;
    }

    .tiny {
      font-size: 8px;
    }

    .summary-table td,
    .summary-table th {
      border: 1px solid #444;
      padding: 4px 5px;
    }

    .summary-table th {
      background: #fff;
      text-align: center;
      font-weight: bold;
    }

    .right {
      text-align: right;
    }

    .center {
      text-align: center;
    }

    .contracts th,
    .contracts td {
      border: 1px solid #444;
      padding: 3px 4px;
      font-size: 7.2px;
      vertical-align: middle;
    }

    .contracts th {
      background: #e9ecef;
      text-align: center;
      font-weight: bold;
    }

    .cards th,
    .cards td {
      border: 1px solid #444;
      padding: 3px 4px;
      font-size: 8px;
      vertical-align: middle;
    }

    .cards th {
      background: #e9ecef;
      text-align: center;
      font-weight: bold;
    }

    .note {
      font-size: 8px;
      margin-top: 4px;
    }

    .auth-block {
      margin-top: 14px;
      font-size: 9px;
    }

    .qr-wrap {
      margin-top: 8px;
      width: 100%;
      text-align: left;
    }

    .qr-box {
      width: 82px;
      height: 82px;
      border: 1px solid #999;
      display: inline-block;
      text-align: center;
      line-height: 82px;
      font-size: 16px;
      color: #666;
    }

    .qr-box img {
      width: 82px;
      height: 82px;
      display: block;
    }

    .footer-line {
      position: absolute;
      left: 0;
      right: 0;
      bottom: 22px;
      border-top: 1px solid #bcbcbc;
      height: 0;
    }

    .footer-mark {
      position: absolute;
      width: 120px;
      height: 4px;
      background: #005db8;
      left: 50%;
      margin-left: -60px;
      bottom: 20px;
    }

    .footer-left {
      position: absolute;
      bottom: 2px;
      left: 0;
      font-size: 9px;
      color: #005db8;
      font-weight: bold;
    }

    .footer-right {
      position: absolute;
      bottom: 2px;
      right: 0;
      font-size: 9px;
      text-align: right;
    }

    .brand-inss {
      font-size: 18px;
      font-weight: bold;
      color: #0070c9;
    }

    .spacer-6 { height: 6px; }
    .spacer-10 { height: 10px; }
    .spacer-14 { height: 14px; }
  </style>
</head>
<body>

  <div class="page">
    <div class="top-band"><div class="blue">Instituto Nacional do Seguro Social</div></div>

    <div class="doc-title">Instituto Nacional do Seguro Social</div>
    <div class="doc-title">HISTÓRICO DE<br>EMPRÉSTIMO CONSIGNADO</div>

    <div style="font-size:14px; font-weight:bold; margin-bottom:10px;">' . $nome . '</div>

    <table class="header-table">
      <tr>
        <td class="left-col">
          <div><span class="field-label">Benefício</span></div>
          <div>' . $especieDescricao . '</div>

          <div class="spacer-6"></div>
          <div><span class="field-label">Nº Benefício:</span> ' . $esc($beneficioFormatado) . '</div>
          <div><span class="field-label">Situação:</span> ' . $situacao . '</div>
          <div><span class="field-label">Pago em:</span> ' . $bancoPagamento . '</div>
          <div><span class="field-label">Meio:</span> ' . $meioPagamento . '</div>
          <div><span class="field-label">Agência:</span> ' . $agencia . '</div>
          <div><span class="field-label">Conta Corrente:</span> ' . $conta . '</div>
        </td>
        <td class="right-col">
          <div>' . $possuiProcurador . '</div>
          <div>' . $possuiRepresentante . '</div>
          <div>' . $pensaoAlimenticia . '</div>
          <div>' . $elegivelEmprestimo . '</div>

          <div class="spacer-14"></div>

          ' . $page1QrBlock . '
        </td>
      </tr>
    </table>

    <div class="spacer-10"></div>

    <div class="subtitle">Quantitativo de Empréstimos por Situação</div>
    <table class="summary-table">
      <tr>
        <th style="width:70%;">SITUAÇÃO</th>
        <th style="width:30%;">QUANTIDADE</th>
      </tr>
      <tr>
        <td>ATIVOS</td>
        <td class="center">' . (int) $ativosTotal . '</td>
      </tr>
      <tr>
        <td>SUSPENSOS</td>
        <td class="center">' . (int) $suspensosTotal . '</td>
      </tr>
      <tr>
        <td>RESERVADOS PORTABILIDADE</td>
        <td class="center">0</td>
      </tr>
      <tr>
        <td>RESERVADOS REFINANCIAMENTO</td>
        <td class="center">0</td>
      </tr>
    </table>

    <div class="auth-block">
      <div>Você pode conferir a autenticidade do documento</div>
      <div>' . $esc($authText) . '</div>
    </div>

    <div class="footer-line"></div>
    <div class="footer-mark"></div>
    <div class="footer-left"><span class="brand-inss">INSS</span></div>
    <div class="footer-right">' . $esc($generatedAt) . '<br>1 / 3</div>
  </div>

  <div class="page">
    <div class="top-band"><div class="blue">Instituto Nacional do Seguro Social</div></div>

    <div class="gray-strip">Margem para Empréstimo/Cartão e Resumo Financeiro</div>

    <table class="summary-table">
      <tr>
        <th colspan="2">VALORES DO BENEFÍCIO</th>
      </tr>
      <tr>
        <td><strong>BASE DE CÁLCULO</strong></td>
        <td class="right">' . $formatMoney($baseCalculo) . '</td>
      </tr>
      <tr>
        <td><strong>MÁXIMO DE COMPROMETIMENTO PERMITIDO</strong></td>
        <td class="right">' . $formatMoney($maxComprometimento) . '</td>
      </tr>
      <tr>
        <td><strong>TOTAL COMPROMETIDO</strong></td>
        <td class="right">' . $formatMoney($totalComprometido) . '</td>
      </tr>
      <tr>
        <td><strong>MARGEM EXTRAPOLADA***</strong></td>
        <td class="right">R$0,00</td>
      </tr>
    </table>

    <div class="spacer-10"></div>

    <table class="summary-table">
      <tr>
        <th style="width:34%;"></th>
        <th style="width:22%;">EMPRÉSTIMOS</th>
        <th style="width:22%;">RMC</th>
        <th style="width:22%;">RCC</th>
      </tr>
      <tr>
        <td><strong>BASE DE CÁLCULO</strong></td>
        <td class="right">' . $formatMoney($baseCalculo) . '</td>
        <td class="right">' . $formatMoney($baseCalculo) . '</td>
        <td class="right">' . $formatMoney($baseCalculo) . '</td>
      </tr>
      <tr>
        <td><strong>MARGEM CONSIGNÁVEL*</strong></td>
        <td class="right">' . $formatMoney($margemConsignavel) . '</td>
        <td class="right">' . $formatMoney(((float) $baseCalculo) * 0.05) . '</td>
        <td class="right">' . $formatMoney(((float) $baseCalculo) * 0.05) . '</td>
      </tr>
      <tr>
        <td><strong>MARGEM UTILIZADA</strong></td>
        <td class="right">' . $formatMoney($margemUtilizadaEmprestimo) . '</td>
        <td class="right">' . $formatMoney($margemRmc) . '</td>
        <td class="right">' . $formatMoney($margemRcc) . '</td>
      </tr>
      <tr>
        <td><strong>MARGEM RESERVADA**</strong></td>
        <td class="right">R$0,00</td>
        <td class="center">-</td>
        <td class="center">-</td>
      </tr>
      <tr>
        <td><strong>MARGEM DISPONÍVEL</strong></td>
        <td class="right">' . $formatMoney($margemDisponivelEmprestimoValor) . '</td>
        <td class="right">' . $formatMoney($margemDisponivelRmc) . '</td>
        <td class="right">' . $formatMoney($margemDisponivelRcc) . '</td>
      </tr>
      <tr>
        <td><strong>MARGEM EXTRAPOLADA***</strong></td>
        <td class="right">R$0,00</td>
        <td class="right">R$0,00</td>
        <td class="right">R$0,00</td>
      </tr>
    </table>

    <div class="spacer-10"></div>

    <div class="note">
      * Para benefícios das espécies, 18, 87 e 88 a margem consignável representa 30% da base de cálculo para empréstimos e 5% para cartão, podendo optar por somente uma das modalidades RMC ou RCC. Para as demais espécies, a margem consignável atual representa 35% da base de cálculo para empréstimos, 10% para cartão, sendo 5% para RMC e 5% para RCC.
    </div>
    <div class="note">
      ** O valor da margem reservada está incluído no valor da margem utilizada.
    </div>
    <div class="note">
      *** A margem extrapolada representa o valor que excedeu a margem disponível da modalidade ou o máximo de comprometimento do benefício.
    </div>

    <div class="footer-line"></div>
    <div class="footer-mark"></div>
    <div class="footer-left"><span class="brand-inss">INSS</span></div>
    <div class="footer-right">' . $esc($generatedAt) . '<br>2 / 3</div>
  </div>

  <div class="page">
    <div class="top-band"><div class="blue">Instituto Nacional do Seguro Social</div></div>

    <div class="doc-title" style="font-size:14px;">EMPRÉSTIMOS BANCÁRIOS</div>
    <div class="gray-strip" style="font-size:11px;">CONTRATOS ATIVOS E SUSPENSOS*</div>

    <table class="contracts">
      <tr>
        <th>CONTRATO</th>
        <th>BANCO</th>
        <th>SITUAÇÃO</th>
        <th>ORIGEM DA AVERBAÇÃO</th>
        <th>DATA INCLUSÃO</th>
        <th>INÍCIO DE DESCONTO</th>
        <th>FIM DE DESCONTO</th>
        <th>QTDE PARCELAS</th>
        <th>PARCELA</th>
        <th>EMPRESTADO</th>
        <th>LIBERADO</th>
        <th>IOF</th>
        <th>CET MENSAL</th>
        <th>CET ANUAL</th>
        <th>TAXA JUROS MENSAL</th>
        <th>TAXA JUROS ANUAL</th>
        <th>VALOR PAGO</th>
        <th>PRIMEIRO DESCONTO</th>
        <th>SUSPENS. BANCO</th>
        <th>SUSPENS. INSS</th>
      </tr>
      ' . $emprestimoRowsHtml . '
    </table>

    <div class="note">*Contratos que comprometem a margem consignável.</div>
    <div class="note">**Valor pago a título de dívida do cliente (refinanciamento e portabilidade).</div>

    <div class="footer-line"></div>
    <div class="footer-mark"></div>
    <div class="footer-left"><span class="brand-inss">INSS</span></div>
    <div class="footer-right">' . $esc($generatedAt) . '<br>2 / 3</div>
  </div>

  <div class="page last">
    <div class="top-band"><div class="blue">Instituto Nacional do Seguro Social</div></div>

    <div class="doc-title" style="font-size:14px;">CARTÃO DE CRÉDITO</div>

    <table class="cards" style="margin-bottom:18px;">
      <tr>
        <th colspan="11">CARTÃO DE CRÉDITO - RMC</th>
      </tr>
      <tr>
        <th colspan="11" style="text-align:left; font-size:10px; color:#666;">CONTRATOS ATIVOS E SUSPENSOS*</th>
      </tr>
      <tr>
        <th>CONTRATO</th>
        <th>BANCO</th>
        <th>SITUAÇÃO</th>
        <th>ORIGEM DA AVERBAÇÃO</th>
        <th>DATA INCLUSÃO</th>
        <th>LIMITE DE CARTÃO</th>
        <th>RESERVADO</th>
        <th>SUSPENSÃO BANCO</th>
        <th>SUSPENSÃO INSS</th>
        <th>REATIVAÇÃO BANCO</th>
        <th>REATIVAÇÃO INSS</th>
      </tr>
      ' . $rmcRowsHtml . '
    </table>

    <div class="note">* Contratos que comprometem a margem consignável</div>

    <table class="cards" style="margin-top:14px;">
      <tr>
        <th colspan="11">CARTÃO DE CRÉDITO - RCC</th>
      </tr>
      <tr>
        <th colspan="11" style="text-align:left; font-size:10px; color:#666;">CONTRATOS ATIVOS E SUSPENSOS*</th>
      </tr>
      <tr>
        <th>CONTRATO</th>
        <th>BANCO</th>
        <th>SITUAÇÃO</th>
        <th>ORIGEM DA AVERBAÇÃO</th>
        <th>DATA INCLUSÃO</th>
        <th>LIMITE DE CARTÃO</th>
        <th>RESERVADO</th>
        <th>SUSPENSÃO BANCO</th>
        <th>SUSPENSÃO INSS</th>
        <th>REATIVAÇÃO BANCO</th>
        <th>REATIVAÇÃO INSS</th>
      </tr>
      ' . $rccRowsHtml . '
    </table>

    <div class="note">* Contratos que comprometem a margem consignável</div>

    <div class="footer-line"></div>
    <div class="footer-mark"></div>
    <div class="footer-left"><span class="brand-inss">INSS</span></div>
    <div class="footer-right">' . $esc($generatedAt) . '<br>3 / 3</div>
  </div>

</body>
</html>';
  }
}
