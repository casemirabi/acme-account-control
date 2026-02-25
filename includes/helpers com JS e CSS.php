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

add_action('wp_ajax_acme_clt_query', 'acme_ajax_clt_query');

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
}

/* ============================================================
 * 7) JS FRONT (FORM CLT)
 * ============================================================
 */

add_action('wp_enqueue_scripts', function () {
  if (!is_user_logged_in()) return;

  wp_register_script('acme-clt-form', '', [], null, true);
  wp_enqueue_script('acme-clt-form');

  wp_register_style('acme-clt-style', false);
  wp_enqueue_style('acme-clt-style');

  // CSS (mantido + um ajuste visual leve em mensagens)
  $css = '
.acme-card{border:1px solid #e5e7eb;border-radius:14px;background:#fff;box-shadow:0 6px 18px rgba(0,0,0,.06)}
.acme-card-h{display:flex;align-items:center;justify-content:space-between;padding:14px 14px;border-bottom:1px solid #f1f5f9}
.acme-title{font-weight:800;font-size:16px;color:#0f172a}
.acme-actions{display:flex;gap:8px;align-items:center}
.acme-card-b{padding:14px}
.acme-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}
@media (max-width: 640px){.acme-grid{grid-template-columns:1fr}}
.acme-field{border:1px solid #f1f5f9;border-radius:12px;padding:10px;background:#fbfdff}
.acme-label{font-size:12px;color:#475569;margin-bottom:4px}
.acme-value{font-size:14px;font-weight:800;color:#0f172a}
.acme-muted{color:#475569;font-size:13px}
.acme-badge{font-size:12px;font-weight:900;border-radius:999px;padding:6px 10px;border:1px solid transparent;white-space:nowrap}
.acme-badge-ok{background:#ecfdf5;color:#047857;border-color:#a7f3d0}
.acme-badge-bad{background:#fef2f2;color:#b91c1c;border-color:#fecaca}
.acme-summary{cursor:pointer;font-weight:800;color:#0f172a}
.acme-tbl{width:100%;border-collapse:collapse;font-size:12px}
.acme-tbl th,.acme-tbl td{border:1px solid #e5e7eb;padding:8px;vertical-align:top}
.acme-tbl th{background:#f8fafc;text-align:left}
.acme-btn{display:inline-block;padding:8px 10px;border-radius:12px;font-weight:900;font-size:12px;border:1px solid #e2e8f0;background:#0b4ea2;color:#fff;text-decoration:none}
.acme-btn:hover{opacity:.92}
.acme-msg{border:1px solid #e5e7eb;border-radius:12px;padding:12px;background:#fff;color:#0f172a}
.acme-msg-err{border-color:#fecaca;background:#fef2f2;color:#991b1b}
.acme-msg-ok{border-color:#a7f3d0;background:#ecfdf5;color:#065f46}
';
  wp_add_inline_style('acme-clt-style', $css);

  wp_localize_script('acme-clt-form', 'ACME_CLT', [
    'restStart'  => rest_url('acme/v1/api-clt'),
    'restStatus' => rest_url('acme/v1/api-clt-status'),
    'restNonce'  => wp_create_nonce('wp_rest'),
    'pdfUrl'     => admin_url('admin-ajax.php?action=acme_clt_pdf_request&_wpnonce=' . wp_create_nonce('acme_clt_pdf_nonce')),
  ]);

  // JS
  $js = <<<'JS'

  function softReload(){
  try{
      // mantém a tela “no mesmo lugar”
      const y = window.scrollY || 0;
      sessionStorage.setItem('acme_scroll_y', String(y));
    }catch(e){}
    window.location.reload();
  }

  document.addEventListener('DOMContentLoaded', () => {
    try{
      const y = Number(sessionStorage.getItem('acme_scroll_y') || '0');
      if(y > 0) window.scrollTo(0, y);
      sessionStorage.removeItem('acme_scroll_y');
    }catch(e){}
  });

function storageKey(){ return 'acme_clt_last_result_v1'; }

function saveLastResult(payload){
  try{ sessionStorage.setItem(storageKey(), JSON.stringify(payload)); }catch(e){}
}
function loadLastResult(){
  try{
    const raw = sessionStorage.getItem(storageKey());
    if(!raw) return null;
    return JSON.parse(raw);
  }catch(e){ return null; }
}
function clearLastResult(){
  try{ sessionStorage.removeItem(storageKey()); }catch(e){}
}

(function(){
  function onlyDigits(s){ return String(s||'').replace(/\D+/g,''); }
  function el(id){ return document.getElementById(id); }
  function sleep(ms){ return new Promise(r => setTimeout(r, ms)); }

  function renderMsg(box, html, kind){
    var cls = 'acme-msg' + (kind === 'err' ? ' acme-msg-err' : (kind === 'ok' ? ' acme-msg-ok' : ''));
    box.innerHTML = '<div class="'+cls+'">'+html+'</div>';
  }

  function fmtMoney(v){
    const n = Number(v || 0);
    return n.toLocaleString('pt-BR', { style:'currency', currency:'BRL' });
  }

  function fmtDate(iso){
    if(!iso) return '—';
    const d = new Date(iso);
    if(isNaN(d.getTime())) return '—';
    return d.toLocaleDateString('pt-BR');
  }

  // cpf do banco vem mascarado "000****07" OU pode vir completo.
  // aqui só garantimos uma máscara "bonitinha" quando vier 11 dígitos.
  function maskCpfAny(cpf){
    cpf = String(cpf||'').trim();
    const digits = cpf.replace(/\D+/g,'');
    if(digits.length === 11){
      return digits.slice(0,3)+'.'+digits.slice(3,6)+'.'+digits.slice(6,9)+'-'+digits.slice(9,11);
    }
    // se já veio mascarado, só retorna como veio
    return cpf || '***.***.***-**';
  }

  // nome do root (agora sempre vem do server)
  // - se vier vazio por algum motivo, cai em "Dados restringidos"
  function showNome(v){
    v = (v === null || v === undefined) ? '' : String(v).trim();
    return v ? v : 'Dados restringidos';
  }


  function sexoLabel(v){
    v = String(v||'').toUpperCase();
    if(v === 'F') return 'Feminino';
    if(v === 'M') return 'Masculino';
    return '—';
  }

  // ============================================================
  // Normalização do retorno (compatível com formatos antigos + novo do BANCO)
  // ============================================================
  //
  // Aceita:
  // - dadosRaw como objeto: { cpf, vinculos:[...], margem:{...}, propostas:{...} }
  // - dadosRaw como array de 1 item: [ { ... } ]  <-- seu response_json.dados
  // - dadosRaw como array de vínculos (legado)
  //
  function normalizeDados(dadosRaw){
    // 1) formato do banco: array com 1 objeto
    if(Array.isArray(dadosRaw)){
      // se for array de objetos do novo formato
      if(dadosRaw.length > 0 && dadosRaw[0] && typeof dadosRaw[0] === 'object' && (dadosRaw[0].margem || dadosRaw[0].vinculos || dadosRaw[0].cpf)){
        const root = dadosRaw[0];
        const vinc = Array.isArray(root.vinculos) ? root.vinculos : [];
        return { root: root, vinculos: vinc };
      }

      // se for legado: array de vínculos
      return { root: null, vinculos: dadosRaw };
    }

    // 2) objeto direto (novo)
    if(dadosRaw && typeof dadosRaw === 'object'){
      const root = dadosRaw;
      const vinc = Array.isArray(root.vinculos) ? root.vinculos : [];
      return { root: root, vinculos: vinc };
    }

    return { root: null, vinculos: [] };
  }

  // elegível: qualquer vinculo.elegivel == true
  // (mantém compatibilidade com "Elegivel" legado)
  function hasEligible(vinculos){
    if(!Array.isArray(vinculos) || vinculos.length === 0) return false;
    return vinculos.some(v => {
      if(!v || typeof v !== 'object') return false;
      if(Object.prototype.hasOwnProperty.call(v, 'elegivel')) return v.elegivel === true || v.elegivel === 1 || v.elegivel === 'true';
      if(Object.prototype.hasOwnProperty.call(v, 'Elegivel')) return v.Elegivel === true || v.Elegivel === 1 || v.Elegivel === 'true';
      return false;
    });
  }

  // detecta se há simulações (capturedResponse.body) e retorna array parseado
  function extractSimulacoes(root){
    try{
      const body = root && root.propostas && root.propostas.capturedResponse && root.propostas.capturedResponse.body;
      if(!body) return null;
      const arr = JSON.parse(body);
      return Array.isArray(arr) ? arr : null;
    }catch(e){
      return null;
    }
  }

  function makePdfLink(requestId){
    return ACME_CLT.pdfUrl + '&request_id=' + encodeURIComponent(requestId);
  }

  // ============================================================
  // Card final (somente completed)
  // ============================================================
  function renderResultCard(container, dadosRaw, requestId){
    const norm = normalizeDados(dadosRaw);
    const root = norm.root;
    const vinculos = norm.vinculos;

    // Se não tem root no novo formato e também não tem vínculos (legado vazio), devolve msg
    if(!root && (!Array.isArray(vinculos) || vinculos.length === 0)){
      container.innerHTML = '<div class="acme-msg acme-msg-err">Nenhum dado retornado.</div>';
      return;
    }

    // Campos do front (vêm do root.margem)
    const margem = (root && root.margem && typeof root.margem === 'object') ? root.margem : {};

    const nome = showNome(root && root.nome ? root.nome : '');
    const cpf  = maskCpfAny(root && root.cpf ? root.cpf : '');
    const valorMargemDisponivel = fmtMoney(margem.valorMargemDisponivel);
    const valorMargemBase       = fmtMoney(margem.valorMargemBase);
    const dataNascimento        = fmtDate(margem.dataNascimento);
    const sexo                  = sexoLabel(margem.sexo);
    const dataAdmissao          = fmtDate(margem.dataAdmissao);

    const elegivel = hasEligible(vinculos);
    const badgeClass = elegivel ? 'acme-badge acme-badge-ok' : 'acme-badge acme-badge-bad';
    const badgeText  = elegivel ? 'Elegível' : 'Não elegível';

    const pdfHref = requestId ? makePdfLink(requestId) : '#';

    // Simulações (se existir capturedResponse.body)
    const simulacoes = extractSimulacoes(root);
    const hasSim = Array.isArray(simulacoes) && simulacoes.length > 0;

    // Detalhes: ou tabela de vínculos, ou tabela de simulações
    let detailsTitle = '';
    let detailsBody  = '';

    if(hasSim){
      detailsTitle = `Simulações (${simulacoes.length})`;

      const simRows = simulacoes.map(s => {
        const nomeSim = (s && s.nome) ? String(s.nome) : '—';
        const prazo   = (s && s.prazo !== undefined && s.prazo !== null) ? String(s.prazo) : '—';
        const taxa    = (s && s.taxaJuros !== undefined && s.taxaJuros !== null) ? String(s.taxaJuros) + '%' : '—';
        const valorLib= (s && s.valorLiberado !== undefined && s.valorLiberado !== null) ? fmtMoney(s.valorLiberado) : '—';
        const parcela = (s && s.valorParcela !== undefined && s.valorParcela !== null) ? fmtMoney(s.valorParcela) : '—';

        return `<tr>
          <td>${nomeSim}</td>
          <td>${prazo}</td>
          <td>${taxa}</td>
          <td>${valorLib}</td>
          <td>${parcela}</td>
        </tr>`;
      }).join('');

      detailsBody = `
        <div style="overflow:auto; margin-top:8px;">
          <table class="acme-tbl">
            <thead>
              <tr>
                <th>Nome</th>
                <th>Prazo</th>
                <th>Taxa</th>
                <th>Valor liberado</th>
                <th>Parcela</th>
              </tr>
            </thead>
            <tbody>${simRows}</tbody>
          </table>
        </div>
      `;
    } else {
      detailsTitle = `Ver vínculos retornados (${Array.isArray(vinculos)?vinculos.length:0})`;

      const rows = (Array.isArray(vinculos) ? vinculos : []).map(v => {
        const elg = (v && (v.elegivel === true || v.Elegivel === true || v.elegivel === 1 || v.Elegivel === 1 || v.elegivel === 'true' || v.Elegivel === 'true')) ? 'Sim' : 'Não';
        const reg = (v && (v.numeroRegistro || v.NumeroRegistro)) ? String(v.numeroRegistro || v.NumeroRegistro) : '—';
        const doc = (v && (v.numeroDocumento || v.NumeroDocumento)) ? String(v.numeroDocumento || v.NumeroDocumento) : '—';
        const cnpj= (v && (v.numeroDocumentoEmpregador || v.NumeroDocumentoEmpregador)) ? String(v.numeroDocumentoEmpregador || v.NumeroDocumentoEmpregador) : '—';

        return `<tr>
          <td>${elg}</td>
          <td>${reg}</td>
          <td>${doc}</td>
          <td>${cnpj}</td>
        </tr>`;
      }).join('');

      detailsBody = `
        <div style="overflow:auto; margin-top:8px;">
          <table class="acme-tbl">
            <thead>
              <tr>
                <th>Elegível</th>
                <th>Registro</th>
                <th>Documento</th>
                <th>CNPJ Empregador</th>
              </tr>
            </thead>
            <tbody>${rows}</tbody>
          </table>
        </div>
      `;
    }

    container.innerHTML = `
      <div class="acme-card">
        <div class="acme-card-h">
          <div class="acme-title">Resultado da Consulta CLT</div>
          <div class="acme-actions">
            <a class="acme-btn" href="${pdfHref}" target="_blank" rel="noopener">Baixar PDF</a>
            <div class="${badgeClass}">${badgeText}</div>
          </div>
        </div>

        <div class="acme-card-b">
          <div class="acme-grid">
            <div class="acme-field">
              <div class="acme-label">Nome</div>
              <div class="acme-value">${nome}</div>
            </div>

            <div class="acme-field">
              <div class="acme-label">CPF</div>
              <div class="acme-value">${cpf}</div>
            </div>

            <div class="acme-field">
              <div class="acme-label">Margem disponível</div>
              <div class="acme-value">${valorMargemDisponivel}</div>
            </div>

            <div class="acme-field">
              <div class="acme-label">Margem base</div>
              <div class="acme-value">${valorMargemBase}</div>
            </div>

            <div class="acme-field">
              <div class="acme-label">Nascimento</div>
              <div class="acme-value">${dataNascimento}</div>
            </div>

            <div class="acme-field">
              <div class="acme-label">Sexo</div>
              <div class="acme-value">${sexo}</div>
            </div>

            <div class="acme-field">
              <div class="acme-label">Admissão</div>
              <div class="acme-value">${dataAdmissao}</div>
            </div>

            <div class="acme-field">
              <div class="acme-label">Status</div>
              <div class="acme-value">COMPLETED</div>
            </div>
          </div>

          <details style="margin-top:12px;">
            <summary class="acme-summary">${detailsTitle}</summary>
            ${detailsBody}
          </details>

        </div>
      </div>
    `;
  }

  // ============================================================
  // REST calls
  // ============================================================
  async function startConsulta(cpf){
    const resp = await fetch(ACME_CLT.restStart, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/json',
        'X-WP-Nonce': ACME_CLT.restNonce
      },
      body: JSON.stringify({
        cpf: String(cpf || ''),
        wait: true,
        wait_timeout: 25
      })
    });

    const text = await resp.text();
    let json = null;
    try { json = JSON.parse(text); } catch {}

    if (!resp.ok) {
      console.error("API-CLT HTTP error body:", text);
      const msg =
        (json && json.error && json.error.message) ? json.error.message :
        (json && json.error) ? json.error :
        (json && json.message) ? json.message :
        text || `HTTP ${resp.status}`;
      throw new Error(msg);
    }

    return json;
  }

  async function getStatus(requestId){
    const url = ACME_CLT.restStatus + '?request_id=' + encodeURIComponent(requestId);
    const resp = await fetch(url, { method: 'GET', credentials: 'same-origin' });
    const json = await resp.json().catch(()=>null);

    if(!resp.ok){
      const msg = (json && (json.error || json.message)) ? (json.error || json.message) : `Erro (${resp.status})`;
      throw new Error(msg);
    }
    if(!json || json.success !== true) throw new Error('Erro ao consultar status.');
    return json;
  }

  // status pode vir em st.status, st.data.status, etc.
  function extractStatus(st){
    if(!st) return '';
    if(st.status) return String(st.status);
    if(st.data && st.data.status) return String(st.data.status);
    if(st.data && st.data.request && st.data.request.status) return String(st.data.request.status);
    if(st.row && st.row.status) return String(st.row.status);
    return '';
  }

  // extrai "dados" do retorno do START
  function extractDadosFromStart(started){
    if(!started) return null;

    // alguns backends retornam { response_data: { dados: ... } }
    if(started.response_data && started.response_data.dados !== undefined) return started.response_data.dados;
    if(started.data && started.data.response_data && started.data.response_data.dados !== undefined) return started.data.response_data.dados;

    // outros retornam { dados: ... }
    if(started.dados !== undefined) return started.dados;

    // fallback
    if(started.response_data !== undefined) return started.response_data;

    return null;
  }

  // extrai "dados" do retorno do STATUS
  function extractDadosFromStatus(st){
    if(!st) return null;

    if(st.data && st.data.response_data && st.data.response_data.dados !== undefined) return st.data.response_data.dados;
    if(st.data && st.data.dados !== undefined) return st.data.dados;

    if(st.response_data && st.response_data.dados !== undefined) return st.response_data.dados;
    if(st.dados !== undefined) return st.dados;

    return null;
  }

  // ============================================================
  // Runner: espera até 1:30 por completed/failed
  // ============================================================
  async function run(){
    var input = el('acme-cpf');
    var box   = el('acme-clt-result');
    var btn   = el('acme-btn');

    if(!input || !box || !btn) return;

    var cpf = onlyDigits(input.value);
    if(cpf.length !== 11){
      renderMsg(box, 'CPF inválido. Digite 11 números.', 'err');
      return;
    }

    btn.disabled = true;
    renderMsg(box, 'Iniciando consulta…', '');

    try {
      const started = await startConsulta(cpf);
      const requestId = started.request_id;

      // Se o start já finalizou
      if (started.status === 'completed') {
        const dadosRaw = extractDadosFromStart(started);
        renderResultCard(box, dadosRaw, requestId);
        saveLastResult({ requestId, status: 'completed', dados: dadosRaw });
        softReload(); // ✅ add
        return;
      }

      if (started.status === 'failed') {
        const err = (started.error && started.error.message) ? started.error.message : 'Falha na consulta.';
        renderMsg(box, 'Falhou: ' + err, 'err');
        saveLastResult({ requestId, status: 'failed', error: err });
        softReload(); // ✅ add
        return;
      }

      // Pending/processing: polling
      renderMsg(box, 'Consulta iniciada ✅<br><b>Processando… (tempo estimado: 1 min 30 s))</b>', '');

      const t0 = Date.now();
      const timeoutMs  = 90000; // 1:30
      const intervalMs = 3000;  // checa a cada 3s

      while (true) {
        await sleep(intervalMs);

        const st = await getStatus(requestId);
        const status = extractStatus(st);

        // se ainda não veio status, considera pendente
        if (!status || status === 'pending' || status === 'processing') {
          if (Date.now() - t0 > timeoutMs) {
          renderMsg(
              box,
              'Ainda processando…<br>O tempo estimado de 1 minuto e 30 segundos foi excedido.<br><a href="/consultas-clt">Acompanhar status da consulta</a>',
              ''
            );
  
          //renderMsg(box, 'Ainda processando…<br>Passou de 1:30. Tenta novamente em alguns segundos 😉', '');
            return;
          }
          continue;
        }

        if (status === 'failed') {
          const err =
            (st.data && st.data.error && st.data.error.message) ? st.data.error.message :
            (st.error && st.error.message) ? st.error.message :
            'Falha na consulta.';
          renderMsg(box, 'Falhou: ' + err, 'err');
          saveLastResult({ requestId, status: 'failed', error: err });
          softReload();
          return;
        }

        if (status === 'completed') {
          const dadosRaw = extractDadosFromStatus(st);
          renderResultCard(box, dadosRaw, requestId);
          saveLastResult({ requestId, status: 'completed', dados: dadosRaw });
          softReload(); // ✅ add
          return;
        }

        

        // qualquer outro status explícito do backend
        renderMsg(box, 'Status inesperado: ' + status, 'err');
        return;
      }

    } catch (e) {
      renderMsg(box, (e && e.message) ? e.message : 'Falha na requisição.', 'err');
    } finally {
      btn.disabled = false;
    }
  }

  // Render de “último resultado” caso tenha sido salvo (ex.: refresh)
  document.addEventListener('DOMContentLoaded', function(){
    const box = el('acme-clt-result');
    if(!box) return;

    const saved = loadLastResult();
    if(saved && saved.dados && saved.status === 'completed'){
      renderResultCard(box, saved.dados, saved.requestId);
      clearLastResult();
    } else if(saved && saved.status === 'failed'){
      renderMsg(box, saved.error || 'Falha na consulta.', 'err');
      clearLastResult();
    }
  });

  document.addEventListener('click', function(e){
    if(e.target && e.target.id === 'acme-btn'){
      e.preventDefault();
      run();
    }
  });
})();
JS;

  wp_add_inline_script('acme-clt-form', $js);
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
  .badge{ display:inline-block; padding:4px 10px; border-radius:12px; background:' . $badge_bg . '; color:' . $badge_fg . '; font-weight:700; }
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
            <div><span class="label">Status consulta:</span> COMPLETED</div>
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
 * ✅ PDF por request_id (fluxo novo, compatível com clt_requests/response_json)
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
  $table = $wpdb->prefix . 'clt_requests';

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
    $service_id = acme_get_service_id_by_slug((string) $service_slug);
    if (!$service_id) return false;
    return acme_credit_balance_user((int) $user_id, (int) $service_id) > 0;
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

    $service_id = acme_get_service_id_by_slug((string) $service_slug);
    if (!$service_id) return ['ok' => false, 'error' => 'Serviço inválido (slug não existe em wp_services)'];

    $lotsT = $wpdb->prefix . 'credit_lots';
    $txT   = $wpdb->prefix . 'credit_transactions';
    $ctT   = $wpdb->prefix . 'credit_contracts';
    $now   = current_time('mysql');

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
</style>';
  }
}