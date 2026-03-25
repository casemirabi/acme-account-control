<?php
if (!defined('ABSPATH'))
  exit;

/**
 * ============================================================
 * ACME CLT Async (Mock/Real)
 * Endpoints:
 * - POST /wp-json/acme/v1/api-clt
 * - GET  /wp-json/acme/v1/api-clt-status?request_id=...
 * - POST /wp-json/acme/v1/clt-webhook   (recebe o resultado e finaliza)
 *
 * Ajuste principal:
 * - Fornecedor retorna um request_id próprio (ex: clt_ml88ek02_qbdavwed)
 * - Salvamos esse ID em provider_request_id
 * - No webhook, aceitamos tanto o request_id interno quanto o do fornecedor
 *
 * ✅ AJUSTE NOVO (IMPORTANTE):
 * - Alguns providers retornam success=false, mas ainda assim a consulta "executou"
 *   e trouxe dados (vínculos/margem/status etc). Nesses casos:
 *     -> marcamos como COMPLETED (executado) e consumimos crédito
 *     -> guardamos error_code/error_message como "warning" (para debug/auditoria)
 * - Só marcamos FAILED (e NÃO consome crédito) quando não há sinal de execução.
 * ============================================================
 */

add_action('rest_api_init', function () {

  // Inicia consulta (cria request e dispara mock/real)
  register_rest_route('acme/v1', '/api-clt', [
    'methods' => 'POST',
    'callback' => 'acme_api_clt_start',
    'permission_callback' => '__return_true',
  ]);

  // Consulta status pela request interna (clt_XXXX)
  register_rest_route('acme/v1', '/api-clt-status', [
    'methods' => 'GET',
    'callback' => 'acme_api_clt_status',
    'permission_callback' => '__return_true',
  ]);

  // Webhook do fornecedor (POST)
  register_rest_route('acme/v1', '/clt-webhook', [
    'methods' => 'POST',
    'callback' => 'acme_api_clt_webhook',
    'permission_callback' => '__return_true',
  ]);
});

/* ============================================================
 * INICIO TESTES DE RECUSA E SUCESSO (ADMIN ONLY)
 * ============================================================
 */

add_action('rest_api_init', function () {

  // ✅ Simula SUCESSO (somente admin)
  register_rest_route('acme/v1', '/clt-simulate-success', [
    'methods' => 'POST',
    'callback' => 'acme_clt_simulate_success',
    'permission_callback' => function () {
      return current_user_can('manage_options');
    },
  ]);

  // ❌ Simula RECUSA/FALHA (somente admin)
  register_rest_route('acme/v1', '/clt-simulate-fail', [
    'methods' => 'POST',
    'callback' => 'acme_clt_simulate_fail',
    'permission_callback' => function () {
      return current_user_can('manage_options');
    },
  ]);
});

function acme_clt_simulate_success(WP_REST_Request $req)
{
  global $wpdb;

  $rid = (string) ($req->get_param('request_id') ?? '');
  if (!$rid)
    return acme_err(400, 'request_id obrigatório', 'MISSING_REQUEST_ID');

  $reqT = $wpdb->prefix . 'service_requests';
  $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$reqT} WHERE request_id=%s LIMIT 1", $rid), ARRAY_A);
  if (!$row)
    return acme_err(404, 'Requisição não encontrada', 'NOT_FOUND');

  // Payload fake (simula fornecedor)
  $dados = [
    'status' => 'ok',
    'mensagem' => 'Consulta realizada com sucesso (simulada)',
    'itens' => [
      ['tipo' => 'vinculo', 'descricao' => 'Emprego A', 'desde' => '2023-01-01', 'ate' => null],
      ['tipo' => 'vinculo', 'descricao' => 'Emprego B', 'desde' => '2021-05-10', 'ate' => '2022-12-20'],
    ],
  ];

  // Finaliza igual webhook real
  $wpdb->update($reqT, [
    'status' => 'completed',
    'response_json' => wp_json_encode(['dados' => $dados], JSON_UNESCAPED_UNICODE),
    'completed_at' => current_time('mysql'),
    'updated_at' => current_time('mysql'),
    'error_code' => null,
    'error_message' => null,
  ], ['request_id' => $rid]);

  return acme_ok(['ok' => true, 'request_id' => $rid, 'status' => 'completed'], 200);
}

function acme_clt_simulate_fail(WP_REST_Request $req)
{
  global $wpdb;

  $rid = (string) ($req->get_param('request_id') ?? '');
  if (!$rid)
    return acme_err(400, 'request_id obrigatório', 'MISSING_REQUEST_ID');

  $reqT = $wpdb->prefix . 'service_requests';
  $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$reqT} WHERE request_id=%s LIMIT 1", $rid), ARRAY_A);
  if (!$row)
    return acme_err(404, 'Requisição não encontrada', 'NOT_FOUND');

  $code = (string) ($req->get_param('code') ?? 'REFUSED');
  $msg = (string) ($req->get_param('message') ?? 'Consulta recusada (simulada)');

  $wpdb->update($reqT, [
    'status' => 'failed',
    'completed_at' => current_time('mysql'),
    'updated_at' => current_time('mysql'),
    'error_code' => $code,
    'error_message' => $msg,
  ], ['request_id' => $rid]);

  return acme_ok(['ok' => true, 'request_id' => $rid, 'status' => 'failed'], 200);
}

/* ============================================================
 * Helpers
 * ============================================================
 */

if (!function_exists('acme_ok')) {
  function acme_ok($data, int $status = 200)
  {
    return new WP_REST_Response($data, $status);
  }
}

if (!function_exists('acme_err')) {
  function acme_err(int $status, string $message, string $code = 'ERROR', array $extra = [])
  {
    return new WP_REST_Response(array_merge([
      'success' => false,
      'error' => [
        'code' => $code,
        'message' => $message,
      ],
    ], $extra), $status);
  }
}

if (!function_exists('acme_make_request_id')) {
  function acme_make_request_id(): string
  {
    return 'clt_' . substr(md5(uniqid('', true)), 0, 12);
  }
}

if (!function_exists('acme_mask_cpf')) {
  function acme_mask_cpf(string $cpf_numbers): string
  {
    $cpf_numbers = preg_replace('/\D/', '', $cpf_numbers);
    if (strlen($cpf_numbers) !== 11)
      return $cpf_numbers;
    return substr($cpf_numbers, 0, 3) . '.***.***-' . substr($cpf_numbers, 9, 2);
  }
}

if (!function_exists('acme_internal_webhook_url')) {
  function acme_internal_webhook_url(): string
  {
    return rest_url('acme/v1/clt-webhook');
  }
}

if (!function_exists('acme_clt_trace')) {
  function acme_clt_trace(string $rid, string $evt, string $msg, array $extra = [])
  {
    if (!defined('WP_DEBUG') || !WP_DEBUG)
      return;
    error_log('[ACME_CLT][' . $rid . '][' . $evt . '] ' . $msg . (!empty($extra) ? ' | ' . wp_json_encode($extra) : ''));
  }
}

/**
 * ✅ ESSENCIAL: wait (poll curto no banco)
 * - permite POST /api-clt com {"wait":true,"wait_timeout":25}
 * - se finalizar rápido, já devolve completed/failed no mesmo request
 */
if (!function_exists('acme_clt_wait_for_completion')) {
  function acme_clt_wait_for_completion(string $rid, int $timeoutSeconds = 25, int $intervalMs = 700): array
  {
    global $wpdb;
    $t = $wpdb->prefix . 'service_requests';

    $timeoutSeconds = max(1, min(55, (int) $timeoutSeconds));
    $intervalMs = max(200, min(2000, (int) $intervalMs));

    $deadline = microtime(true) + $timeoutSeconds;

    while (microtime(true) < $deadline) {
      $row = $wpdb->get_row($wpdb->prepare(
        "SELECT status, response_json, error_code, error_message, provider_request_id, completed_at, updated_at
           FROM {$t}
          WHERE request_id=%s
          LIMIT 1",
        $rid
      ), ARRAY_A);

      if ($row && ($row['status'] === 'completed' || $row['status'] === 'failed')) {
        return ['timed_out' => false] + $row;
      }

      usleep($intervalMs * 1000);
    }

    return ['timed_out' => true, 'status' => 'pending'];
  }
}

/* ============================================================
 * POST /api-clt
 * ============================================================
 */
if (!function_exists('acme_resolve_authenticated_user_id')) {
  function acme_resolve_authenticated_user_id(WP_REST_Request $req): int
  {
    // 1️⃣ Sessão normal WP
    $uid = (int) get_current_user_id();
    if ($uid > 0) {
      return $uid;
    }

    // 2️⃣ API Key via header
    $apiKey = (string) $req->get_header('x-acme-key');
    if ($apiKey !== '' && function_exists('acme_validate_api_key')) {

      $consumerData = acme_validate_api_key($apiKey, 'clt');

      if (!is_wp_error($consumerData)) {
        return (int) ($consumerData['wp_user_id'] ?? 0);
      }
    }

    // 3️⃣ Ambiente local (dev only)
    /*$host = $_SERVER['HTTP_HOST'] ?? '';
    if (strpos($host, 'localhost') !== false || strpos($host, '127.0.0.1') !== false) {
      return 1;
    }*/

    return 0;
  }
}


function acme_api_clt_start(WP_REST_Request $req)
{
  global $wpdb;

  $params = $req->get_json_params();
  if (!is_array($params))
    $params = [];

  // Sanitiza CPF
  $cpf = preg_replace('/\D+/', '', (string) ($params['cpf'] ?? ''));
  if (strlen($cpf) !== 11) {
    return acme_err(400, 'CPF inválido. Deve conter 11 dígitos.', 'INVALID_CPF');
  }

  // Usa sempre o webhook interno (não aceita webhook do cliente)
  $webhook_url = acme_internal_webhook_url();

  // Requer login
  $uid = acme_resolve_authenticated_user_id($req);

  if (!$uid) {
    return acme_err(401, 'Você precisa estar logado ou informar uma API key válida.', 'NOT_AUTHENTICATED');
  }
  /*$uid = get_current_user_id();

  if (!$uid) {
    $host = $_SERVER['HTTP_HOST'] ?? '';
    if (strpos($host, 'localhost') !== false || strpos($host, '127.0.0.1') !== false) {
      $uid = 1; // dev only
    } else {
      return acme_err(401, 'Você precisa estar logado.', 'NOT_LOGGED_IN');
    }
  }*/

  // Valida créditos (se existir engine)
  if (function_exists('acme_user_has_credit')) {
    if (!acme_user_has_credit($uid, 'clt')) {
      return acme_err(402, 'Sem créditos', 'NO_CREDITS');
    }
  }

  // IDs internos
  /*$rid = acme_make_request_id();
  $clt_request_id = wp_generate_uuid4(); // OBS: esse UUID é seu (não do fornecedor)*/
  // IDs internos
  $rid = acme_make_request_id();
  $clt_request_id = wp_generate_uuid4(); // continua sendo salvo internamente

  // Saldo atual de créditos do usuário para o serviço CLT
  $service_id = function_exists('acme_get_service_id_by_slug')
    ? (int) acme_get_service_id_by_slug('clt')
    : 0;

  $userCredits = ($service_id > 0 && function_exists('acme_credit_balance_user'))
    ? (int) acme_credit_balance_user((int) $uid, $service_id)
    : 0;

  // Insere request pendente
  $reqT = $wpdb->prefix . 'service_requests';
  $wpdb->insert($reqT, [
    'user_id' => (int) $uid,
    'request_id' => $rid,
    'clt_request_id' => $clt_request_id,
    'provider_request_id' => null,
    'service_slug' => 'clt',
    'cpf_hash' => hash('sha256', $cpf),
    'cpf_masked' => acme_mask_cpf($cpf),
    'numeroDocumento' => $cpf,
    'webhook_url' => $webhook_url,
    'status' => 'pending',
    'created_at' => current_time('mysql'),
    'updated_at' => current_time('mysql'),
  ]);

  if (!$wpdb->insert_id) {
    return acme_err(500, 'Falha ao criar requisição.', 'DB_ERROR');
  }

  /**
   * ✅ ESSENCIAL: o enqueue no Node precisa acontecer AGORA,
   * senão o wait nunca tem chance de completar (WP-Cron é imprevisível).
   *
   * Importante:
   * - Isso NÃO roda Playwright pesado no WP
   * - Só faz um wp_remote_post rápido no /enqueue (timeout curto)
   */
  if (defined('ACME_CLT_MOCK') && ACME_CLT_MOCK) {
    acme_clt_trace($rid, 'MOCK_SCHEDULED', 'Mock agendado via cron');
    wp_schedule_single_event(time() + rand(10, 40), 'acme_clt_finish', [$rid]);
  } else {
    acme_clt_trace($rid, 'REAL_DISPATCH_NOW', 'Dispatcher real chamado imediatamente (apenas enqueue)');
    do_action('acme_clt_real_dispatch', $rid, $cpf, $webhook_url);
  }

  // Base response
  $base = [
    'success' => true,
    'message' => 'Consulta CLT iniciada. Acompanhe pelo request_id ou aguarde o webhook.',
    'request_id' => $rid,
    'credits' => $userCredits, //'clt_request_id' => $clt_request_id,
    'status' => 'pending',
  ];

  // ✅ ESSENCIAL: WAIT opcional
  $wait = !empty($params['wait']);
  $wait_timeout = isset($params['wait_timeout']) ? (int) $params['wait_timeout'] : 25;

  if ($wait) {
    $row = acme_clt_wait_for_completion($rid, $wait_timeout);

    // se não finalizou no tempo, devolve pending com flags
    if (!empty($row['timed_out']) && ($row['status'] ?? '') === 'pending') {
      $base['waited'] = true;
      $base['timed_out'] = true;
      $base['wait_timeout'] = max(1, min(55, (int) $wait_timeout));
      return acme_ok($base, 200);
    }

    // completed
    if (($row['status'] ?? '') === 'completed') {
      $base['waited'] = true;
      $base['status'] = 'completed';
      $base['provider_request_id'] = $row['provider_request_id'] ?? null;
      $base['completed_at'] = $row['completed_at'] ?? null;
      $base['response_data'] = !empty($row['response_json']) ? json_decode($row['response_json'], true) : null;

      /*=====================================*/


      $mensagemPadrao = 'Consulta CLT concluída.';

      $mensagemNegocio = null;

      if (!empty($row['error_message'])) {
        $mensagemNegocio = (string) $row['error_message'];

        $base['business_warning'] = [
          'code' => $row['error_code'] ?? null,
          'message' => $mensagemNegocio,
        ];
      }

      if ($mensagemNegocio) {
        $base['message'] = $mensagemPadrao . ' ' . $mensagemNegocio;
      } else {
        $base['message'] = $mensagemPadrao;
      }

      /*=====================================*/

      return acme_ok($base, 200);
    }

    // failed
    if (($row['status'] ?? '') === 'failed') {
      $base['waited'] = true;
      $base['status'] = 'failed';
      $base['provider_request_id'] = $row['provider_request_id'] ?? null;
      $base['message'] = 'Consulta CLT falhou.';
      $base['error'] = acme_clt_public_failed_error();
      return acme_ok($base, 200);
    }
  }

  // Retorno normal (pending)
  return acme_ok($base, 200);
}

/* ============================================================
 * GET /api-clt-status
 * Normalização do Json dew saída
 * ============================================================
 */

if (!function_exists('acme_clt_build_public_status_response')) {
  function acme_clt_build_public_status_response(array $responseData): array
  {
    $dados = $responseData['dados'] ?? null;

    if (!is_array($dados)) {
      return ['dados' => []];
    }

    $dadosNormalizados = [];
    foreach ($dados as $item) {
      if (!is_array($item)) {
        continue;
      }

      $margem = is_array($item['margem'] ?? null) ? $item['margem'] : [];
      $vinculos = is_array($item['vinculos'] ?? null) ? $item['vinculos'] : [];
      $propostas = is_array($item['propostas'] ?? null) ? $item['propostas'] : [];

      $vinculosPublicos = [];
      foreach ($vinculos as $vinculo) {
        if (!is_array($vinculo)) {
          continue;
        }

        $vinculosPublicos[] = [
          'elegivel' => (bool) ($vinculo['elegivel'] ?? false),
          'numeroRegistro' => $vinculo['numeroRegistro'] ?? null,
        ];
      }

      $cpfPublico = $item['cpf_full'] ?? $item['cpf'] ?? $item['cpf_masked'] ?? null;

      $propostasPublicas = [];
      $capturedResponseBody = $propostas['capturedResponse']['body'] ?? '';

      if (is_string($capturedResponseBody) && $capturedResponseBody !== '') {
        $propostasDecodificadas = json_decode($capturedResponseBody, true);

        if (is_array($propostasDecodificadas)) {
          foreach ($propostasDecodificadas as $proposta) {
            if (!is_array($proposta)) {
              continue;
            }

            $tipoCredito = is_array($proposta['tipoCredito'] ?? null) ? $proposta['tipoCredito'] : [];

            $propostasPublicas[] = [
              'id' => $proposta['id'] ?? null,
              'nome' => $proposta['nome'] ?? null,
              'prazo' => $proposta['prazo'] ?? null,
              'taxaJuros' => $proposta['taxaJuros'] ?? null,
              'valorLiberado' => $proposta['valorLiberado'] ?? null,
              'valorParcela' => $proposta['valorParcela'] ?? null,
              'taxaSeguro' => $proposta['taxaSeguro'] ?? null,
              'valorSeguro' => $proposta['valorSeguro'] ?? null,
              'tipoCredito' => [
                'id' => $tipoCredito['id'] ?? null,
                'name' => $tipoCredito['name'] ?? null,
              ],
              'type' => $proposta['type'] ?? null,
            ];
          }
        }
      }

      $dadosNormalizados[] = [
        'ok' => (bool) ($item['ok'] ?? false),
        'numeroDocumento' => $cpfPublico, //$item['cpf_masked'] ?? $item['cpf'] ?? null,
        'nome' => $item['nome'] ?? null,
        'status' => $item['status'] ?? [],
        'vinculos' => $vinculosPublicos,
        'margem' => [
          'valorMargemDisponivel' => $margem['valorMargemDisponivel'] ?? null,
          'valorMargemBase' => $margem['valorMargemBase'] ?? null,
          'valorTotalDevido' => $margem['valorTotalDevido'] ?? null,
          'registroEmpregaticio' => $margem['registroEmpregaticio'] ?? null,
          'cnpjEmpregador' => $margem['cnpjEmpregador'] ?? null,
          'dataAdmissao' => $margem['dataAdmissao'] ?? null,
          'dataNascimento' => $margem['dataNascimento'] ?? null,
          'sexo' => $margem['sexo'] ?? null,
        ],
        'propostas' => !empty($propostasPublicas)
          ? $propostasPublicas
          : 'Não há propostas disponíveis no momento.',
      ];
    }

    return ['dados' => $dadosNormalizados];
  }
}
//=============================

/* ============================================================
 * GET /api-clt-status
 * ============================================================
 */
function acme_api_clt_status(WP_REST_Request $req)
{
  global $wpdb;

  $rid = (string) $req->get_param('request_id');
  if (!$rid)
    return acme_err(400, 'request_id obrigatório', 'MISSING_REQUEST_ID');

  $row = $wpdb->get_row($wpdb->prepare(
    "SELECT * FROM {$wpdb->prefix}service_requests WHERE request_id=%s LIMIT 1",
    $rid
  ), ARRAY_A);

  if (!$row)
    return acme_err(404, 'Requisição não encontrada', 'NOT_FOUND');

  $uid = acme_resolve_authenticated_user_id($req);
  if (!$uid) {
    return acme_err(401, 'Você precisa estar logado ou informar uma API key válida.', 'NOT_AUTHENTICATED');
  }

  // Opcional mas recomendado: impedir consulta de outro usuário
  if (!current_user_can('manage_options') && (int)$row['user_id'] !== $uid) {
    return acme_err(403, 'Você não tem permissão para consultar esta requisição.', 'REQUEST_NOT_OWNED_BY_USER');
  }

  // Retorno base
  $data = [
    'request_id' => $row['request_id'],
    'provider_request_id' => $row['provider_request_id'] ?? null,
    'cpf' => $row['cpf_masked'],
    'status' => $row['status'],
    'created_at' => $row['created_at'],
    'updated_at' => $row['updated_at'],
    'completed_at' => $row['completed_at'],
  ];

  if ($row['status'] === 'completed') {

    if (!empty($row['response_json'])) {
      //$data['response_data'] = json_decode($row['response_json'], true);
      /*Nomrmalização da saída do endpoint status */
      $decodedResponse = json_decode($row['response_json'], true);
      $data['response_data'] = acme_clt_build_public_status_response(
        is_array($decodedResponse) ? $decodedResponse : []
      );
    }
    if (!empty($row['error_message'])) {
      $data['business_warning'] = [
        'code' => $row['error_code'] ?? null,
        'message' => $row['error_message'],
      ];
    }

    $data['message'] = 'Consulta CLT concluída.';
  }

  if (!function_exists('acme_clt_public_failed_error')) {
    function acme_clt_public_failed_error(): array
    {
      return [
        'code' => 'FAILED',
        'message' => "Houve um erro no processamento da consulta. Revise os dados, aguarde alguns instantes e tente novamente. Se o problema persistir, entre em contato com o administrador.",
      ];
    }
  }

  if ($row['status'] === 'failed') {
    $data['error'] = acme_clt_public_failed_error();
  }

  return acme_ok([
    'success' => true,
    'data' => $data,
  ], 200);
}

/* ============================================================
 * Webhook: finaliza request (aceita request_id interno OU provider_request_id)
 * ============================================================
 */

function acme_api_clt_webhook(WP_REST_Request $req)
{
  global $wpdb;

  /**
   * 1) Lê o payload do webhook (sempre como array)
   * - O provider pode mandar JSON inválido ou vazio; aqui garantimos estrutura segura.
   */
  $payload = $req->get_json_params();
  if (!is_array($payload)) {
    $payload = [];
  }

  // Log técnico interno do payload bruto (útil para auditoria/debug)
  acme_clt_trace((string) ($payload['request_id'] ?? '??'), 'WEBHOOK_RAW', 'Payload recebido', $payload);

  /**
   * 2) Identifica a requisição no banco
   * - Aceitamos request_id (nosso) ou provider_request_id (do fornecedor).
   */
  $rid = (string) ($payload['request_id'] ?? '');
  $provider_rid = (string) ($payload['provider_request_id'] ?? '');

  if (!$rid && !$provider_rid) {
    return acme_err(400, 'request_id ou provider_request_id obrigatório', 'MISSING_REQUEST_ID');
  }

  $reqT = $wpdb->prefix . 'service_requests';

  // Busca preferencial por request_id; fallback por provider_request_id
  $row = null;
  if ($rid) {
    $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$reqT} WHERE request_id=%s LIMIT 1", $rid), ARRAY_A);
  }
  if (!$row && $provider_rid) {
    $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$reqT} WHERE provider_request_id=%s LIMIT 1", $provider_rid), ARRAY_A);
  }

  if (!$row) {
    return acme_err(404, 'Requisição não encontrada', 'NOT_FOUND');
  }

  // Garante que estamos usando o request_id do nosso banco
  $rid = (string) $row['request_id'];

  /**
   * 3) Captura sinalização padrão do provider
   * - Alguns providers usam "success", outros "ok".
   * - Isso NÃO é a única fonte de verdade: temos regras de negócio e fallback por execução.
   */
  $success = (bool) ($payload['success'] ?? $payload['ok'] ?? false);

  /**
   * 4) Extrai possíveis dados de resposta (quando a consulta realmente executou)
   * - Mesmo se success=false, pode ter executado e retornado "dados".
   * - Normalizamos para uma lista (array de itens) para persistência consistente.
   */
  $dados = $payload['dados'] ?? null;
  if (!is_array($dados)) $dados = null;

  $dados_list = null;
  if (is_array($dados)) {
    $is_assoc = array_keys($dados) !== range(0, count($dados) - 1);
    $dados_list = $is_assoc ? [$dados] : $dados;
  }

  /**
   * 5) Fallback: detectar “executou” mesmo com success=false
   * - Se houver sinais claros de execução (cpf/vinculos/margem/status/rid), tratamos como completed.
   * - Isso evita perder execução real por sinalização inconsistente do fornecedor.
   */
  $looks_executed = false;
  $root0 = (is_array($dados_list) && isset($dados_list[0]) && is_array($dados_list[0])) ? $dados_list[0] : null;
  if (is_array($root0)) {
    if (!empty($root0['rid'])) $looks_executed = true;
    if (isset($root0['status']) && is_array($root0['status']) && count($root0['status']) > 0) $looks_executed = true;
    if (array_key_exists('vinculos', $root0) || array_key_exists('margem', $root0) || array_key_exists('propostas', $root0)) $looks_executed = true;
    if (array_key_exists('cpf', $root0)) $looks_executed = true;
  }

  /**
   * 6) Normaliza mensagens/códigos do provider
   * - raw_error_message pode conter detalhes técnicos (infra).
   * - public_error_message é uma versão segura para o cliente (sem infra).
   */
  /*$raw_error_code = (string) ($payload['code'] ?? $payload['error_code'] ?? ($success ? '' : 'FAILED'));
  $raw_error_message = (string) ($payload['error'] ?? $payload['message'] ?? ($success ? '' : 'Falha na consulta'));*/

  $raw_error_code = (string) ($payload['code'] ?? $payload['error_code'] ?? ($success ? '' : 'FAILED'));

  $errorField = $payload['error'] ?? $payload['message'] ?? null;

  if (is_array($errorField)) {
    $raw_error_message = (string) ($errorField['message'] ?? 'Houve um erro no processamento da consulta. \nRevise os dados, aguarde alguns instantes e tente novamente. Se o problema persistir, entre em contato com o administrador.');
  } else {
    $raw_error_message = (string) ($errorField ?? ($success ? '' : 'Houve um erro no processamento da consulta. \nRevise os dados, aguarde alguns instantes e tente novamente. Se o problema persistir, entre em contato com o administrador.'));
  }

  $raw_message_lc = mb_strtolower(trim($raw_error_message), 'UTF-8');

  /**
   * 7) Regras de negócio: mapeamento por “log/mensagem” e/ou error_code
   * PRIORIDADE:
   *   A) Mapeamento explícito (por error_code ou mensagem) para decidir completed/failed
   *   B) Se não casar, usa success || looks_executed
   *
   * - completed: consulta executou, mesmo que o resultado seja “desagradável” (ex.: não elegível / cpf não encontrado).
   * - failed: erro técnico/autenticação/infra (não executou ou não é confiável como execução).
   */
  $forced_status = null; // 'completed' | 'failed' | null

  // ---- COMPLETED (executou / resultado de negócio) ----

  /**
   * Caso explícito por error_code:
   * - cpf_nao_encontrado_dataprev_esocial
   *   => Consulta executou normalmente
   *   => Não é erro técnico
   *   => Apenas força status como completed
   *   => NÃO altera error_code nem error_message
   */
  if (!$forced_status && $raw_error_code === 'cpf_nao_encontrado_dataprev_esocial') {
    $forced_status = 'completed';
  }

  /**
   * Caso: CPF não encontrado (por mensagem)
   * - Para alguns providers isso vem como texto em "message/error".
   * - Tratamos como consulta executada (completed), pois houve tentativa real e retorno de negócio.
   */
  if (
    !$forced_status &&
    (str_contains($raw_message_lc, 'cpf não encontrado') || str_contains($raw_message_lc, 'cpf nao encontrado'))
  ) {
    $forced_status = 'completed';
  }

  /**
   * Caso: consultou CPF mas nenhum vínculo elegível (resultado de negócio)
   */
  if (
    !$forced_status &&
    $raw_message_lc === mb_strtolower('CPF consultado, mas nenhum vínculo está elegível para simulação.', 'UTF-8')
  ) {
    $forced_status = 'completed';
  }

  /**
   * Caso: código/mensagem de negócio (sem registro/empregador)
   */
  if (!$forced_status && $raw_message_lc === 'vinculo_sem_registro_ou_empregador') {
    $forced_status = 'completed';
  }

  // ---- FAILED (erro técnico/autenticação/infra) ----

  /**
   * Caso: falha de negócio que vocês decidiram tratar como falha (não executou corretamente)
   */
  if (!$forced_status && $raw_message_lc === 'falha_vinculo') {
    $forced_status = 'failed';
  }

  /**
   * Caso: não autorizado (401) => falha real (não deve debitar)
   */
  if (
    !$forced_status &&
    (preg_match('/\bhttp\s*401\b/i', $raw_error_message) || $raw_message_lc === 'http 401')
  ) {
    $forced_status = 'failed';
  }

  /**
   * Caso: step não visível no fluxo automatizado => falha técnica de execução
   */
  if (!$forced_status && $raw_message_lc === 'vinculos_step_not_visible') {
    $forced_status = 'failed';
  }

  if (
    !$forced_status &&
    in_array($raw_error_code, ['cpf_invalido_ou_sem_retorno', 'CPF_INVALIDO_PORTAL'], true)
  ) {
    $forced_status = 'failed';
  }


  /**
   * Infra/DNS/HTTP client:
   * - Erros de rede/infra nunca devem vazar para o cliente final.
   * - Esses casos são "failed" e a mensagem pública vira "Erro técnico".
   */
  $is_infra_error =
    str_contains($raw_message_lc, 'curl error') ||
    str_contains($raw_message_lc, 'could not resolve host') ||
    str_contains($raw_message_lc, 'resolve host') ||
    str_contains($raw_message_lc, 'timeout') ||
    str_contains($raw_message_lc, 'timed out');

  if (!$forced_status && $is_infra_error) {
    $forced_status = 'failed';
  }

  /**
   * 8) Decide status final
   * - Se houve mapeamento explícito, respeita.
   * - Senão, usa (success || looks_executed).
   */
  $final_status = $forced_status ?: (($success || $looks_executed) ? 'completed' : 'failed');

  /**
   * 9) Monta update para persistência
   * - response_json: só quando completed e temos dados.
   * - error_code/error_message:
   *    - No banco podemos manter código/mensagem retornada (para auditoria e suporte).
   *    - Mas o retorno ao cliente deve ser sanitizado em casos de infra.
   */
  $update = [
    'status' => $final_status,
    'completed_at' => current_time('mysql'),
    'updated_at' => current_time('mysql'),
  ];

  // Mensagem segura para o cliente (sem detalhes de infra)
  $public_error_message = $is_infra_error ? 'Erro técnico' : $raw_error_message;

  if ($final_status === 'completed') {
    /**
     * COMPLETED = consulta executou (mesmo que sem resultado elegível)
     * - Salva dados quando existirem.
     * - Mantém error_code/error_message como vieram (não sobrescreve),
     *   porque eles carregam o “status desagradável” que o cliente pode ver.
     */
    if (is_array($dados_list)) {
      $update['response_json'] = wp_json_encode(['dados' => $dados_list], JSON_UNESCAPED_UNICODE);
    }

    /**
     * Importante:
     * - Se success=true, podemos limpar erro (opcional), mas não é obrigatório.
     * - Se success=false, mantemos error_code/error_message para explicar o resultado de negócio.
     * - Porém, se for infra (raro em completed), sanitizamos a mensagem.
     */
    if ($success) {
      $update['error_code'] = null;
      $update['error_message'] = null;
    } else {
      $update['error_code'] = $raw_error_code ?: null;
      $update['error_message'] = $is_infra_error ? 'Erro técnico' : ($raw_error_message ?: null);
    }
  } else {
    /**
     * FAILED = erro técnico/autenticação/infra OU não há sinal de execução
     * - Não consome crédito.
     * - Nunca expõe detalhes de infra no retorno (public_error_message).
     */

    $codigoErroOriginal = trim((string) ($raw_error_code ?: 'FAILED'));
    $mensagemErroOriginal = trim((string) ($raw_error_message ?: ''));

    if ($mensagemErroOriginal !== '') {
      $error = $codigoErroOriginal . ' | ' . $mensagemErroOriginal;
      $update['error_code'] = $error;
    } else {
      $update['error_code'] = $codigoErroOriginal;
    }

    $update['error_message'] = "Houve um erro no processamento da consulta. \nRevise os dados, aguarde alguns instantes e tente novamente. Se o problema persistir, entre em contato com o administrador.";

    /*$update['error_code'] = $raw_error_code ?: 'FAILED';
    $update['error_message'] = $public_error_message ?: 'Erro técnico';*/
  }

  // Persistência no banco
  $wpdb->update($reqT, $update, ['request_id' => $rid]);

  /**
   * 10) Consumo de crédito
   * - Crédito só é consumido quando a consulta foi considerada executada (completed).
   */
  if ($final_status === 'completed') {
    $uid = (int) ($row['user_id'] ?? 0);
    if ($uid && function_exists('acme_consume_credit')) {
      //acme_consume_credit($uid, 'clt', 1, $rid);
      // Importante: NÃO altera o request_id da consulta (fornecedores externos continuam usando $rid).
      $cpfHashParaDebito = (string) ($row['cpf_hash'] ?? '');
      $created_ymd = '';

      if (!empty($row['created_at'])) {
        $created_ymd = date('Y-m-d', strtotime((string) $row['created_at']));
      }

      // fallback: comportamento antigo (não quebra nada)
      $debit_rid = (string) $rid;

      // regra CPF+dia (idempotência) só se tiver os insumos
      if ($cpfHashParaDebito !== '' && $created_ymd !== '') {
        // Se você quiser usar sua função existente, passe o HASH (não o CPF)
        if (function_exists('acme_daily_debit_request_id')) {
          $debit_rid = acme_daily_debit_request_id($uid, 'clt', $cpfHashParaDebito, $created_ymd);
        } else {
          $base = $uid . '|clt|' . $created_ymd . '|' . $cpfHashParaDebito;
          $debit_rid = hash('sha256', $base);
        }
      }

      //acme_consume_credit($uid, 'clt', 1, $debit_rid);
      $cost = acme_get_service_credit_cost('clt');

      if ($cost > 0) {
        acme_consume_credit($uid, 'clt', $cost, $debit_rid);
      }
    }

    /**
     * Log interno:
     * - Para auditoria, registramos o raciocínio sem vazar infra para camadas de negócio.
     * - O payload bruto já está em WEBHOOK_RAW para debug detalhado.
     */
    acme_clt_trace($rid, 'WEBHOOK_DONE', 'Finalizado como completed', [
      'success_flag' => $success,
      'looks_executed' => $looks_executed,
      'forced_status' => $forced_status,
      'error_code' => $raw_error_code ?: null,
      'error_message' => $is_infra_error ? 'Erro técnico' : ($raw_error_message ?: null),
    ]);

    // Retorno simples; sem detalhes de infra
    return acme_ok(['ok' => true, 'status' => 'completed'], 200);
  }

  /**
   * 11) Retorno para FAILED
   * - Sempre retorna status failed.
   * - Nunca retorna detalhes de infra; apenas “Erro técnico” quando aplicável.
   */
  acme_clt_trace($rid, 'WEBHOOK_FAIL', ($raw_error_code ?: 'FAILED') . ' | ' . ($public_error_message ?: 'Erro técnico'));
  return acme_ok(['ok' => true, 'status' => 'failed', 'message' => ($public_error_message ?: 'Erro técnico')], 200);
}


/* ============================================================
 * MOCK: finaliza request pendente (cron)
 * ============================================================
 */

add_action('acme_clt_finish', function ($rid) {
  global $wpdb;

  $reqT = $wpdb->prefix . 'service_requests';
  $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$reqT} WHERE request_id=%s LIMIT 1", $rid), ARRAY_A);
  if (!$row)
    return;

  $ok = (rand(1, 10) <= 8);

  if ($ok) {
    $dados = [
      'status' => 'ok',
      'mensagem' => 'Consulta realizada com sucesso (mock)',
      'itens' => [
        ['tipo' => 'vinculo', 'descricao' => 'Emprego Mock', 'desde' => '2022-02-01', 'ate' => null],
      ],
    ];

    $wpdb->update($reqT, [
      'status' => 'completed',
      'response_json' => wp_json_encode(['dados' => $dados], JSON_UNESCAPED_UNICODE),
      'completed_at' => current_time('mysql'),
      'updated_at' => current_time('mysql'),
      'error_code' => null,
      'error_message' => null,
    ], ['request_id' => $rid]);

    $uid = (int) ($row['user_id'] ?? 0);
    if ($uid && function_exists('acme_consume_credit')) {
      acme_consume_credit($uid, 'clt', 1, $rid);
    }

    acme_clt_trace($rid, 'MOCK_OK', 'Mock finalizado com sucesso');
    return;
  }

  $wpdb->update($reqT, [
    'status' => 'failed',
    'completed_at' => current_time('mysql'),
    'updated_at' => current_time('mysql'),
    'error_code' => 'MOCK_FAIL',
    'error_message' => 'Falha simulada',
  ], ['request_id' => $rid]);

  acme_clt_trace($rid, 'MOCK_FAIL', 'Mock finalizado com falha');
}, 10, 1);

/* ============================================================
 * REAL dispatcher: chama o fornecedor (Supabase) OU acme-services (/enqueue)
 * ============================================================
 */

add_action('acme_clt_real_dispatch', function ($request_id, $cpf, $webhook_url) {
  global $wpdb;

  acme_clt_trace($request_id, 'DISPATCH_START', 'Dispatcher iniciou');

  /**
   * ============================================================
   * NOVO PROVIDER (ACME-SERVICES) — ENQUEUE ASSÍNCRONO
   * ============================================================
   */

  $bridge_url = defined('ACME_CLT_BRIDGE_URL') ? trim((string) ACME_CLT_BRIDGE_URL) : '';
  if ($bridge_url !== '') {

    $bridge_key = '';
    if (defined('ACME_CLT_BRIDGE_KEY') && trim((string) ACME_CLT_BRIDGE_KEY) !== '') {
      $bridge_key = (string) ACME_CLT_BRIDGE_KEY;
    } elseif (defined('ACME_PB_INTERNAL_KEY') && trim((string) ACME_PB_INTERNAL_KEY) !== '') {
      $bridge_key = (string) ACME_PB_INTERNAL_KEY;
    }

    $body_payload = [
      'cpf' => (string) $cpf,
      'request_id' => (string) $request_id,
      'webhook_url' => (string) $webhook_url,
    ];

    acme_clt_trace($request_id, 'BRIDGE_ENQUEUE', 'Enfileirando no acme-services', [
      'bridge_url' => $bridge_url,
    ]);

    $resp = wp_remote_post($bridge_url, [
      'timeout' => 10,
      'headers' => array_filter([
        'Content-Type' => 'application/json',
        'X-Internal-Key' => $bridge_key ?: null,
      ]),
      'body' => wp_json_encode($body_payload),
    ]);

    $reqT = $wpdb->prefix . 'service_requests';

    if (is_wp_error($resp)) {
      $msg = $resp->get_error_message();
      $wpdb->update($reqT, [
        'status' => 'failed',
        'completed_at' => current_time('mysql'),
        'updated_at' => current_time('mysql'),
        'error_code' => 'BRIDGE_HTTP_ERROR',
        'error_message' => $msg,
      ], ['request_id' => $request_id]);

      acme_clt_trace($request_id, 'BRIDGE_FAIL', 'BRIDGE_FAIL | ' . $msg);
      return;
    }

    $code = (int) wp_remote_retrieve_response_code($resp);
    $raw = (string) wp_remote_retrieve_body($resp);
    $json = json_decode($raw, true);

    if ($code >= 400) {
      $wpdb->update($reqT, [
        'status' => 'failed',
        'completed_at' => current_time('mysql'),
        'updated_at' => current_time('mysql'),
        'error_code' => 'BRIDGE_BAD_RESPONSE',
        'error_message' => 'HTTP ' . $code,
      ], ['request_id' => $request_id]);

      acme_clt_trace($request_id, 'BRIDGE_HTTP_FAIL', 'HTTP ' . $code . ' | ' . substr($raw, 0, 900));
      return;
    }

    // salva provider_request_id se o serviço retornar algum id dele
    if (is_array($json)) {
      $provider_id = null;
      if (isset($json['provider_request_id']) && is_string($json['provider_request_id']) && $json['provider_request_id'] !== '') {
        $provider_id = $json['provider_request_id'];
      } elseif (isset($json['request_id']) && is_string($json['request_id']) && $json['request_id'] !== '') {
        $provider_id = $json['request_id'];
      }

      if ($provider_id) {
        $wpdb->update($reqT, [
          'provider_request_id' => $provider_id,
          'updated_at' => current_time('mysql'),
        ], ['request_id' => $request_id]);
      }
    }

    acme_clt_trace($request_id, 'BRIDGE_ENQUEUED', 'Enfileirado no acme-services. Aguardando webhook.', [
      'http' => $code,
      'body' => substr($raw, 0, 500),
    ]);

    return; // mantém pending
  }

  /**
   * ============================================================
   * FORNECEDOR ANTIGO (Supabase) — fallback
   * ============================================================
   */

  $base_ok = defined('ACME_CLT_API_BASE') && trim((string) ACME_CLT_API_BASE) !== '';
  $key_ok = defined('ACME_CLT_API_KEY') && trim((string) ACME_CLT_API_KEY) !== '';

  if (!$base_ok || !$key_ok) {
    acme_clt_trace($request_id, 'CONFIG_MISSING', 'Base/Key ausentes ou vazias');
    return;
  }

  $base = rtrim(ACME_CLT_API_BASE, '/');
  $url = $base . '/api-clt';

  $body_payload = [
    'request_id' => (string) $request_id,
    'cpf' => (string) $cpf,
    'webhook_url' => (string) $webhook_url,
  ];

  $resp = wp_remote_post($url, [
    'timeout' => 60,
    'headers' => [
      'Content-Type' => 'application/json',
      'x-api-key' => ACME_CLT_API_KEY,
    ],
    'body' => wp_json_encode($body_payload),
  ]);

  if (is_wp_error($resp)) {
    acme_clt_trace($request_id, 'HTTP_ERROR', $resp->get_error_message());
    return;
  }

  $code = (int) wp_remote_retrieve_response_code($resp);
  $raw = (string) wp_remote_retrieve_body($resp);
  $json = json_decode($raw, true);

  if ($code >= 400 || !is_array($json)) {
    acme_clt_trace($request_id, 'BAD_RESPONSE', 'HTTP ' . $code . ' | ' . substr($raw, 0, 900));
    return;
  }

  if (!empty($json['request_id']) && is_string($json['request_id'])) {
    $reqT = $wpdb->prefix . 'service_requests';
    $wpdb->update($reqT, [
      'provider_request_id' => $json['request_id'],
      'updated_at' => current_time('mysql'),
    ], ['request_id' => $request_id]);
  }

  acme_clt_trace($request_id, 'DISPATCH_OK', 'Fornecedor aceitou a requisição', [
    'http' => $code,
    'body' => substr($raw, 0, 500),
  ]);
}, 10, 3);
