<?php
if (!defined('ABSPATH'))
  exit;

/**
 * ============================================================
 * ACME — Export Engine (global)
 * - Registry de relatórios exportáveis
 * - 1 endpoint único /admin-post.php?action=acme_export
 * - CSV padrão (Excel) em chunks
 * - PDF nativo (sem lib) para reports específicos (ex: credits_extract_last20)
 * ============================================================
 */

function acme_export_registry(): array
{
  static $registry = null;
  if ($registry !== null)
    return $registry;

  $registry = [];
  $registry = apply_filters('acme_export_registry', $registry);
  return $registry;
}

function acme_export_get_report(string $report_id): array
{
  $all = acme_export_registry();
  return $all[$report_id] ?? [];
}

/** Sanitiza e extrai apenas filtros permitidos */
function acme_export_state_from_request(array $allowed_filters): array
{
  $state = [];
  foreach ($allowed_filters as $key => $rule) {
    $default = $rule['default'] ?? null;
    $raw = $_GET[$key] ?? $default;

    $type = $rule['type'] ?? 'text';

    if ($type === 'int') {
      $state[$key] = (int) $raw;
      continue;
    }

    if ($type === 'date') {
      $v = sanitize_text_field((string) $raw);
      $state[$key] = preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : '';
      continue;
    }

    if ($type === 'enum') {
      $v = sanitize_text_field((string) $raw);
      $allowed = (array) ($rule['allowed'] ?? []);
      $state[$key] = in_array($v, $allowed, true) ? $v : ($default ?? '');
      continue;
    }

    // text
    $state[$key] = sanitize_text_field((string) $raw);
  }
  return $state;
}

/** URL de export mantendo a “view” atual (GET atual) + nonce */
function acme_export_url(string $report_id, array $extra = []): string
{
  $base = admin_url('admin-post.php');

  $current = $_GET;
  unset($current['_acme_nonce'], $current['action'], $current['report']); // evita lixo/duplicação

  $params = array_merge($current, $extra, [
    'action' => 'acme_export',
    'report' => $report_id,
  ]);

  $url = add_query_arg($params, $base);
  $url = wp_nonce_url($url, 'acme_export_' . $report_id, '_acme_nonce');

  return $url;
}

/** Shortcode único do botão */
add_shortcode('acme_export_button', function ($atts) {
  if (!is_user_logged_in())
    return '';

  $atts = shortcode_atts([
    'report' => '',
    'label' => 'Baixar',
    'class' => 'acme-export-link',
  ], $atts);

  $report_id = sanitize_text_field($atts['report']);
  if (!$report_id)
    return '';

  $rep = acme_export_get_report($report_id);
  if (!$rep)
    return '';

  $can = isset($rep['can_export']) && is_callable($rep['can_export'])
    ? (bool) call_user_func($rep['can_export'])
    : true;

  if (!$can)
    return '';

  $url = acme_export_url($report_id);

  return '<a href="' . esc_url($url) . '" class="' . esc_attr($atts['class']) . '">
              <span class="acme-export-ico" aria-hidden="true">⬇</span>
              <span>' . esc_html($atts['label']) . '</span>
            </a>';
});

/**
 * (Opcional) compat: botão legado pra usuários
 * Recomendo usar: [acme_export_button report="users" ...]
 */
add_shortcode('acme_export_users', function ($atts) {
  if (!is_user_logged_in())
    return '';

  $atts = shortcode_atts([
    'label' => 'Baixar usuários',
    'class' => 'acme-export-link',
  ], $atts);

  $rep = acme_export_get_report('users');
  if (!$rep)
    return '';

  if (isset($rep['can_export']) && is_callable($rep['can_export'])) {
    if (!call_user_func($rep['can_export']))
      return '';
  }

  $url = acme_export_url('users');

  return '<a href="' . esc_url($url) . '" class="' . esc_attr($atts['class']) . '">'
    . esc_html($atts['label'])
    . '</a>';
});

@ini_set('display_errors', '0');
@ini_set('html_errors', '0');
@error_reporting(0);

/* ============================================================
 * PDF (sem lib) — helpers
 * ============================================================
 */

function acme_pdf_escape(string $s): string
{
  return str_replace(["\\", "(", ")"], ["\\\\", "\\(", "\\)"], $s);
}

function acme_pdf_to_win1252(string $s): string
{
  // Deja o PDF “core font” trabalhar com acentos
  $out = @iconv('UTF-8', 'Windows-1252//TRANSLIT', $s);
  return $out !== false ? $out : $s;
}

function acme_pdf_num($n): string
{
  // evita vírgula em float pt_BR
  return str_replace(',', '.', (string) $n);
}

function acme_pdf_color_fill($r, $g, $b): string
{
  return acme_pdf_num($r) . ' ' . acme_pdf_num($g) . ' ' . acme_pdf_num($b) . " rg\n";
}
function acme_pdf_color_stroke($r, $g, $b): string
{
  return acme_pdf_num($r) . ' ' . acme_pdf_num($g) . ' ' . acme_pdf_num($b) . " RG\n";
}
function acme_pdf_color_text($r, $g, $b): string
{
  return acme_pdf_num($r) . ' ' . acme_pdf_num($g) . ' ' . acme_pdf_num($b) . " rg\n"; // texto usa rg mesmo
}
function acme_pdf_rect_fill($x, $y, $w, $h, $rgb): string
{
  return "q\n{$rgb}" . acme_pdf_num($x) . ' ' . acme_pdf_num($y) . ' ' . acme_pdf_num($w) . ' ' . acme_pdf_num($h) . " re f\nQ\n";
}
function acme_pdf_rect_stroke($x, $y, $w, $h, $rgb, $lw = 1): string
{
  return "q\n" . acme_pdf_color_stroke(...$rgb) . acme_pdf_num($lw) . " w\n" .
    acme_pdf_num($x) . ' ' . acme_pdf_num($y) . ' ' . acme_pdf_num($w) . ' ' . acme_pdf_num($h) . " re S\nQ\n";
}
function acme_pdf_text($x, $y, $text, $size = 10, $rgb = [0, 0, 0]): string
{
  $t = acme_pdf_escape(acme_pdf_to_win1252((string) $text));
  $col = acme_pdf_color_text($rgb[0], $rgb[1], $rgb[2]);
  return "BT\n{$col}/F1 " . acme_pdf_num($size) . " Tf\n1 0 0 1 " . acme_pdf_num($x) . ' ' . acme_pdf_num($y) . " Tm\n({$t}) Tj\nET\n";
}

/**
 * PDF com layout “bonito” igual seu print:
 * - título
 * - cliente + gerado em
 * - tabela com header cinza, zebra, bordas, saldo colorido
 *
 * $rows esperado: cada item = [data, historico, movimento, saldo]
 */
function acme_output_extrato_pdf_pretty(array $rows, string $cliente, string $filename = 'extrato.pdf'): void
{

  // A4 portrait (pt)
  $pageW = 595;
  $pageH = 842;
  $m = 48;

  $usableW = $pageW - $m * 2;

  // Colunas (ajuste fino aqui)
  $wData = 150;
  $wHist = 215;
  $wMov = 130;
  $wSaldo = $usableW - ($wData + $wHist + $wMov);
  $wSaldo = max(80, $wSaldo); // mínimo

  // se estourar, tira do histórico
  $sumW = $wData + $wHist + $wMov + $wSaldo;
  if ($sumW > $usableW) {
    $diff = $sumW - $usableW;
    $wHist = max(120, $wHist - $diff);
  }

  $rowH = 28;           // altura da linha
  $headerH = 30;        // altura do cabeçalho
  $radiusFake = 0;      // (PDF nativo sem curva fácil — deixamos retinho)

  // Cores
  $bgTable = [1, 1, 1];
  $border = [0.86, 0.86, 0.88];
  $headBg = [0.96, 0.96, 0.97];
  $zebraBg = [0.98, 0.98, 0.99];
  $titleColor = [0.08, 0.09, 0.10];
  $muted = [0.40, 0.42, 0.45];
  $green = [0.00, 0.45, 0.20];
  $red = [0.72, 0.00, 0.12];

  $content = "";

  // Título
  $yTop = $pageH - $m;
  $content .= acme_pdf_text($m, $yTop, "Extrato de Movimentações", 15, $titleColor);

  // Sub-info
  $y = $yTop - 42;
  $content .= acme_pdf_text($m, $y, "Cliente: " . $cliente, 10, $titleColor);

  $y -= 22;
  $gerado = date_i18n('d/m/Y H:i', current_time('timestamp'));
  $content .= acme_pdf_text($m, $y, "Gerado em: " . $gerado, 10, $muted);

  // Tabela container
  $y -= 34;

  $tableX = $m;
  $tableYTop = $y; // topo da tabela (linha do header vai começar aqui)

  // altura total (header + rows)
  $tableH = $headerH + (count($rows) * $rowH);

  // fundo e borda
  $content .= acme_pdf_rect_fill($tableX, $tableYTop - $tableH, $usableW, $tableH, acme_pdf_color_fill(...$bgTable));
  $content .= acme_pdf_rect_stroke($tableX, $tableYTop - $tableH, $usableW, $tableH, $border, 1);


  // linhas verticais (colunas)
  $x1 = $tableX + $wData;
  $x2 = $tableX + $wData + $wHist;
  $x3 = $tableX + $wData + $wHist + $wMov;

  $content .= "q\n" . acme_pdf_color_stroke(...$border) . "1 w\n";
  foreach ([$x1, $x2, $x3] as $xx) {
    $content .= acme_pdf_num($xx) . ' ' . acme_pdf_num($tableYTop) . " m " .
      acme_pdf_num($xx) . ' ' . acme_pdf_num($tableYTop - $tableH) . " l S\n";
  }
  $content .= "Q\n";


  // header bg
  $content .= acme_pdf_rect_fill($tableX, $tableYTop - $headerH, $usableW, $headerH, acme_pdf_color_fill(...$headBg));

  // header text (centralizado verticalmente)
  $padX = 8;
  $hyText = $tableYTop - 20;

  $content .= acme_pdf_text($tableX + $padX, $hyText, "Data", 8, $titleColor);
  $content .= acme_pdf_text($tableX + $wData + $padX, $hyText, "Usuário", 8, $titleColor);
  $content .= acme_pdf_text($tableX + $wData + $wHist + $padX, $hyText, "Serviço", 8, $titleColor);
  $content .= acme_pdf_text($tableX + $wData + $wHist + $wMov + $padX, $hyText, "Saldo", 8, $titleColor);

  // linha divisória header
  $content .= "q\n" . acme_pdf_color_stroke(...$border) . "1 w\n" .
    acme_pdf_num($tableX) . ' ' . acme_pdf_num($tableYTop - $headerH) . " m " .
    acme_pdf_num($tableX + $usableW) . ' ' . acme_pdf_num($tableYTop - $headerH) . " l S\nQ\n";

  // Função simples para truncar texto
  $fit = function (string $text, int $maxChars): string {
    $text = trim($text);
    if (mb_strlen($text) <= $maxChars)
      return $text;
    return mb_substr($text, 0, max(0, $maxChars - 1)) . '…';
  };

  // Rows
  $rowYTop = $tableYTop - $headerH;
  for ($i = 0; $i < count($rows); $i++) {
    $r = $rows[$i];
    $ry = $rowYTop - ($i * $rowH);

    // zebra
    if ($i % 2 === 1) {
      $content .= acme_pdf_rect_fill($tableX, $ry - $rowH, $usableW, $rowH, acme_pdf_color_fill(...$zebraBg));
    }

    // linha inferior
    $content .= "q\n" . acme_pdf_color_stroke(...$border) . "1 w\n" .
      acme_pdf_num($tableX) . ' ' . acme_pdf_num($ry - $rowH) . " m " .
      acme_pdf_num($tableX + $usableW) . ' ' . acme_pdf_num($ry - $rowH) . " l S\nQ\n";

    // texto (alinhamento)
    $ty = $ry - 10;

    $data = (string) ($r[0] ?? '');
    $hist = (string) ($r[1] ?? '');
    $mov = (string) ($r[2] ?? '');
    $saldo = (string) ($r[3] ?? '');

    $content .= acme_pdf_text($tableX + $padX, $ty, $fit($data, 18), 8, $titleColor);
    $content .= acme_pdf_text($tableX + $wData + $padX, $ty, $fit($hist, 26), 8, $titleColor);
    $content .= acme_pdf_text($tableX + $wData + $wHist + $padX, $ty, $fit($mov, 14), 8, $titleColor);

    // saldo colorido
    $saldoNum = (int) preg_replace('/[^\-\d]/', '', $saldo);
    $saldoColor = ($saldoNum < 0) ? $red : $green;

    // deixa com sinal + quando positivo (igual print)
    $saldoTxt = (string) $saldo;
    if ($saldoNum > 0 && strpos($saldoTxt, '+') !== 0)
      $saldoTxt = '+' . $saldoNum;
    if ($saldoNum === 0)
      $saldoTxt = '0';

    $content .= acme_pdf_text($tableX + $wData + $wHist + $wMov + $padX, $ty, $saldoTxt, 8, $saldoColor);
  }

  // Monta PDF (mínimo)
  $objects = [];
  $addObj = function (string $s) use (&$objects): int {
    $objects[] = $s;
    return count($objects);
  };

  $addObj("<< /Type /Catalog /Pages 2 0 R >>"); // 1
  $addObj("<< /Type /Pages /Kids [3 0 R] /Count 1 >>"); // 2
  $addObj("<< /Type /Page /Parent 2 0 R /MediaBox [0 0 {$pageW} {$pageH}]
              /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>"); // 3
  $addObj("<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>"); // 4

  $stream = $content;
  $addObj("<< /Length " . strlen($stream) . " >>\nstream\n{$stream}\nendstream"); // 5

  $pdf = "%PDF-1.4\n";
  $offsets = [0];

  foreach ($objects as $i => $obj) {
    $offsets[] = strlen($pdf);
    $nobj = $i + 1;
    $pdf .= "{$nobj} 0 obj\n{$obj}\nendobj\n";
  }

  $xrefPos = strlen($pdf);
  $pdf .= "xref\n0 " . (count($objects) + 1) . "\n";
  $pdf .= "0000000000 65535 f \n";
  for ($i = 1; $i <= count($objects); $i++) {
    $pdf .= str_pad((string) $offsets[$i], 10, '0', STR_PAD_LEFT) . " 00000 n \n";
  }

  $pdf .= "trailer\n<< /Size " . (count($objects) + 1) . " /Root 1 0 R >>\n";
  $pdf .= "startxref\n{$xrefPos}\n%%EOF";

  header('Content-Type: application/pdf');
  header('Content-Disposition: attachment; filename=' . $filename);
  header('Pragma: no-cache');
  header('Expires: 0');
  echo $pdf;
  exit;
}


/**
 * Gera PDF simples com tabela (1 página).
 * - usa Helvetica + WinAnsiEncoding (acentos OK)
 * - corta/encurta células longas com reticências
 * - bom para 20 linhas
 */
function acme_output_simple_pdf_table(string $title, array $headers, array $rows, string $filename = 'extrato.pdf'): void
{
  $title = acme_pdf_to_win1252($title);

  // A4 portrait em points
  $pageW = 595;
  $pageH = 842;
  $margin = 36;

  // layout
  $y = $pageH - $margin;
  $usableW = $pageW - ($margin * 2);

  $n = max(1, count($headers));

  // widths: default distribuído, mas tenta dar mais pra "Notas" / "Serviço"
  $colW = array_fill(0, $n, (int) floor($usableW / $n));

  $idxNotas = array_search('Notas', $headers, true);
  $idxServ = array_search('Serviço', $headers, true);

  if ($idxNotas !== false)
    $colW[$idxNotas] += 120;
  if ($idxServ !== false)
    $colW[$idxServ] += 60;

  // rebalanceia tirando de colunas menores
  $sum = array_sum($colW);
  if ($sum > $usableW) {
    $over = $sum - $usableW;
    $takeEach = (int) ceil($over / max(1, $n - 2));
    for ($i = 0; $i < $n; $i++) {
      if ($i === $idxNotas || $i === $idxServ)
        continue;
      $colW[$i] = max(60, $colW[$i] - $takeEach);
    }
  }

  // helpers para cortar texto conforme largura aproximada
  $fit = function (string $text, int $w, float $charW = 5.0): string {
    $text = trim($text);
    $maxChars = (int) floor($w / $charW);
    $maxChars = max(6, $maxChars);
    if (strlen($text) <= $maxChars)
      return $text;
    return substr($text, 0, $maxChars - 1) . '…';
  };

  // content stream
  $content = "";

  // title
  $content .= "BT\n/F1 14 Tf\n";
  $content .= "1 0 0 1 {$margin} {$y} Tm\n(" . acme_pdf_escape($title) . ") Tj\nET\n";
  $y -= 22;

  // header row
  $content .= "BT\n/F1 10 Tf\n";
  $x = $margin;
  foreach ($headers as $i => $h) {
    $h = acme_pdf_to_win1252((string) $h);
    $h = $fit($h, $colW[$i] ?? 80, 5.2);
    $content .= "1 0 0 1 {$x} {$y} Tm\n(" . acme_pdf_escape($h) . ") Tj\n";
    $x += $colW[$i] ?? 80;
  }
  $content .= "ET\n";
  $y -= 14;

  // rows
  foreach ($rows as $row) {
    if ($y < $margin + 40)
      break;

    $content .= "BT\n/F1 9 Tf\n";
    $x = $margin;

    $row = array_values((array) $row);
    for ($i = 0; $i < $n; $i++) {
      $cell = $row[$i] ?? '';
      $cell = is_scalar($cell) ? (string) $cell : '';
      $cell = acme_pdf_to_win1252($cell);

      $cw = $colW[$i] ?? 80;
      $cell = $fit($cell, $cw, 5.0);

      $content .= "1 0 0 1 {$x} {$y} Tm\n(" . acme_pdf_escape($cell) . ") Tj\n";
      $x += $cw;
    }

    $content .= "ET\n";
    $y -= 12;
  }

  // Build minimal PDF objects (Helvetica + WinAnsiEncoding)
  $objects = [];
  $addObj = function (string $s) use (&$objects): int {
    $objects[] = $s;
    return count($objects);
  };

  $addObj("<< /Type /Catalog /Pages 2 0 R >>"); // 1
  $addObj("<< /Type /Pages /Kids [3 0 R] /Count 1 >>"); // 2
  $addObj("<< /Type /Page /Parent 2 0 R /MediaBox [0 0 {$pageW} {$pageH}]
              /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>"); // 3
  $addObj("<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>"); // 4

  $stream = $content;
  $addObj("<< /Length " . strlen($stream) . " >>\nstream\n{$stream}\nendstream"); // 5

  $pdf = "%PDF-1.4\n";
  $offsets = [0];

  foreach ($objects as $i => $obj) {
    $offsets[] = strlen($pdf);
    $nobj = $i + 1;
    $pdf .= "{$nobj} 0 obj\n{$obj}\nendobj\n";
  }

  $xrefPos = strlen($pdf);
  $pdf .= "xref\n0 " . (count($objects) + 1) . "\n";
  $pdf .= "0000000000 65535 f \n";
  for ($i = 1; $i <= count($objects); $i++) {
    $pdf .= str_pad((string) $offsets[$i], 10, '0', STR_PAD_LEFT) . " 00000 n \n";
  }

  $pdf .= "trailer\n<< /Size " . (count($objects) + 1) . " /Root 1 0 R >>\n";
  $pdf .= "startxref\n{$xrefPos}\n%%EOF";

  header('Content-Type: application/pdf');
  header('Content-Disposition: attachment; filename=' . $filename);
  header('Pragma: no-cache');
  header('Expires: 0');
  echo $pdf;
  exit;
}

/* ============================================================
 * Endpoint único
 * ============================================================
 */

add_action('admin_post_acme_export', function () {
  if (!is_user_logged_in())
    wp_die('Não autorizado.');

  $report_id = isset($_GET['report']) ? sanitize_text_field((string) $_GET['report']) : '';
  if (!$report_id)
    wp_die('Relatório inválido.');

  if (!isset($_GET['_acme_nonce']) || !wp_verify_nonce($_GET['_acme_nonce'], 'acme_export_' . $report_id)) {
    wp_die('Nonce inválido.');
  }

  $rep = acme_export_get_report($report_id);
  if (!$rep)
    wp_die('Relatório não encontrado.');

  if (isset($rep['can_export']) && is_callable($rep['can_export'])) {
    if (!call_user_func($rep['can_export']))
      wp_die('Sem permissão.');
  }

  // state (precisa existir para CSV e PDF)
  $allowed_filters = (array) ($rep['filters'] ?? []);
  $state = acme_export_state_from_request($allowed_filters);

  if (isset($rep['normalize_state']) && is_callable($rep['normalize_state'])) {
    $state = call_user_func($rep['normalize_state'], $state);
  }

  if (!isset($rep['fetch']) || !is_callable($rep['fetch'])) {
    wp_die('Relatório sem fetch().');
  }

  // ============================================================
  // PDF: substituir Excel -> PDF para este report
  // ============================================================
  if ($report_id === 'credits_extract_last20') {

    // Pega as linhas (o report já limita 20)
    $rows = call_user_func($rep['fetch'], $state, 20, 0);

    // Cabeçalhos e mapeamento específicos para PDF (se existirem)
    $pdfHeaders = isset($rep['pdf_columns']) && is_array($rep['pdf_columns'])
      ? array_values($rep['pdf_columns'])
      : array_values((array) ($rep['columns'] ?? []));

    $pdfRows = [];
    foreach ((array) $rows as $row) {
      if (isset($rep['pdf_map_row']) && is_callable($rep['pdf_map_row'])) {
        $mapped = call_user_func($rep['pdf_map_row'], $row, $state);
      } elseif (isset($rep['map_row']) && is_callable($rep['map_row'])) {
        $cols = array_keys((array) ($rep['columns'] ?? []));
        $mapped = call_user_func($rep['map_row'], $row, $cols, $state);
      } else {
        $mapped = (array) $row;
      }

      $mapped = array_map(fn($v) => is_scalar($v) ? (string) $v : '', (array) $mapped);
      $pdfRows[] = $mapped;
    }

    $filename = 'extrato_' . date('Y-m-d') . '.pdf';
    //acme_output_simple_pdf_table('Extrato (últimas 20)', $pdfHeaders, $pdfRows, $filename);
    $rows = call_user_func($rep['fetch'], $state, 20, 0);

    // monta rows no formato esperado: [data, historico, movimento, saldo]
    $pdfRows = [];
    foreach ((array) $rows as $row) {
      $pdfRows[] = call_user_func($rep['pdf_map_row'], $row, $state);
    }

    // cliente: se tiver target_id, pega nome; senão "Todos"
    $cliente = 'Todos';
    $target_id = (int) ($state['target_id'] ?? 0);
    if ($target_id > 0) {
      $u = get_user_by('id', $target_id);
      if ($u)
        $cliente = $u->display_name;
    }

    $filename = 'extrato_' . date('Y-m-d') . '.pdf';
    acme_output_extrato_pdf_pretty($pdfRows, $cliente, $filename);


  }

  // ============================================================
  // CSV (Excel)
  // ============================================================
  $columns = (array) ($rep['columns'] ?? []);
  if (!$columns)
    wp_die('Relatório sem colunas.');

  $filename = isset($rep['filename']) && is_callable($rep['filename'])
    ? call_user_func($rep['filename'], $state)
    : ($report_id . '_' . date('d/m/Y') . '.csv');

  header('Content-Type: text/csv; charset=UTF-8');
  header('Content-Disposition: attachment; filename=' . $filename);
  header('Pragma: no-cache');
  header('Expires: 0');

  // UTF-8 BOM (pro Excel Windows)
  echo "\xEF\xBB\xBF";

  $out = fopen('php://output', 'w');

  // header
  fputcsv($out, array_values($columns), ';');

  // streaming em chunks
  $chunk = (int) ($rep['chunk_size'] ?? 500);
  $offset = 0;

  while (true) {
    $rows = call_user_func($rep['fetch'], $state, $chunk, $offset);
    if (empty($rows))
      break;

    foreach ($rows as $row) {
      $mapped = isset($rep['map_row']) && is_callable($rep['map_row'])
        ? call_user_func($rep['map_row'], $row, array_keys($columns), $state)
        : $row;

      fputcsv($out, $mapped, ';');
    }

    if (count($rows) < $chunk)
      break;

    $offset += $chunk;
    @set_time_limit(0);
  }

  fclose($out);
  exit;
});


/**
 * Botão de download do Histórico CLT (CSV)
 * Shortcode: [acme_clt_panel_export label="Baixar CSV" class="acme-btn"]
 */
add_shortcode('acme_clt_panel_export', function ($atts) {
  if (!is_user_logged_in())
    return '';

  $atts = shortcode_atts([
    'label' => 'Baixar CSV',
    'class' => 'acme-btn',
  ], $atts);

  if (!function_exists('acme_export_url')) {
    return ''; // export engine não carregado
  }

  $url = acme_export_url('clt_history');

  return '<a href="' . esc_url($url) . '" class="' . esc_attr($atts['class']) . '">
            <span class="acme-export-ico" aria-hidden="true">⬇</span>
            <span>' . esc_html($atts['label']) . '</span>
          </a>';
});
