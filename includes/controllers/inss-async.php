<?php
if (!defined('ABSPATH')) {
  exit;
}

/**
 * ============================================================
 * ACME INSS Async
 * Endpoints:
 * - POST /wp-json/acme/v1/api-inss
 * - GET  /wp-json/acme/v1/api-inss-status?request_id=...
 *
 * Regras:
 * - Sem tabela própria
 * - Reutiliza {$wpdb->prefix}service_requests
 * - Identifica requests do INSS por service_slug = 'inss'
 * - Campo de entrada: numero do beneficio
 * - Crédito validado pelo serviço "inss"
 * - Débito de crédito fica para a finalização real (webhook/provider)
 * ============================================================
 */

add_action('rest_api_init', function () {
  register_rest_route('acme/v1', '/api-inss', [
    'methods'  => 'POST',
    'callback' => 'acme_api_inss_start',
    'permission_callback' => '__return_true',
  ]);

  register_rest_route('acme/v1', '/api-inss-status', [
    'methods'  => 'GET',
    'callback' => 'acme_api_inss_status',
    'permission_callback' => '__return_true',
  ]);
});

add_action('rest_api_init', function () {
  /*register_rest_route('acme/v1', '/inss-simulate-success', [
    'methods'  => 'POST',
    'callback' => 'acme_inss_simulate_success',
    'permission_callback' => function () {
      return current_user_can('manage_options');
    },
  ]);*/
  register_rest_route('acme/v1', '/inss-simulate-success', [
    'methods'  => 'POST',
    'callback' => 'acme_inss_simulate_success',
    'permission_callback' => function (WP_REST_Request $req) {
      $currentUserId = (int) get_current_user_id();

      if ($currentUserId > 0 && user_can($currentUserId, 'manage_options')) {
        return true;
      }

      $apiKey = function_exists('acme_get_api_key_from_request')
        ? acme_get_api_key_from_request($req)
        : (string) $req->get_header('x-acme-key');

      if ($apiKey !== '' && function_exists('acme_validate_api_key')) {
        $consumerData = acme_validate_api_key($apiKey, 'inss');

        if (!is_wp_error($consumerData)) {
          return true;
        }
      }

      return new WP_Error(
        'rest_forbidden',
        'Sem permissão para fazer isso.',
        ['status' => 401]
      );
    },
  ]);

  /*register_rest_route('acme/v1', '/inss-simulate-fail', [
    'methods'  => 'POST',
    'callback' => 'acme_inss_simulate_fail',
    'permission_callback' => function () {
      return current_user_can('manage_options');
    },
  ]);*/
  register_rest_route('acme/v1', '/inss-simulate-fail', [
    'methods'  => 'POST',
    'callback' => 'acme_inss_simulate_fail',
    'permission_callback' => function (WP_REST_Request $req) {
      $currentUserId = (int) get_current_user_id();

      if ($currentUserId > 0 && user_can($currentUserId, 'manage_options')) {
        return true;
      }

      $apiKey = function_exists('acme_get_api_key_from_request')
        ? acme_get_api_key_from_request($req)
        : (string) $req->get_header('x-acme-key');

      if ($apiKey !== '' && function_exists('acme_validate_api_key')) {
        $consumerData = acme_validate_api_key($apiKey, 'inss');

        if (!is_wp_error($consumerData)) {
          return true;
        }
      }

      return new WP_Error(
        'rest_forbidden',
        'Sem permissão para fazer isso.',
        ['status' => 401]
      );
    },
  ]);
});

function acme_inss_simulate_success(WP_REST_Request $req)
{
  global $wpdb;

  $requestId = (string) ($req->get_param('request_id') ?? '');
  if ($requestId === '') {
    return acme_err(400, 'request_id obrigatório', 'MISSING_REQUEST_ID');
  }

  $requestsTable = $wpdb->prefix . 'service_requests';

  $row = $wpdb->get_row(
    $wpdb->prepare(
      "SELECT * FROM {$requestsTable}
       WHERE request_id = %s
         AND service_slug = %s
       LIMIT 1",
      $requestId,
      'inss'
    ),
    ARRAY_A
  );

  if (!$row) {
    return acme_err(404, 'Requisição INSS não encontrada', 'NOT_FOUND');
  }

  $fakePayload = [
    'dados' => [
      'beneficio' => '2279067549',
      'nome' => 'JOAO MARIA ALVES MEIRELES',
      'especie' => [
        'codigo' => 41,
        'descricao' => 'APOSENTADORIA POR IDADE',
      ],
      'situacao' => 'ATIVO',
      'bloqueioEmprestimo' => true,
      'motivoBloqueio' => 'Bloqueado pelo segurado',
      'elegivelEmprestimo' => true,
      'possuiProcurador' => false,
      'possuiRepresentante' => false,
      'pensaoAlimenticia' => false,
      'meioPagamento' => 'Conta Corrente',
      'banco' => [
        'codigo' => '104',
        'descricao' => 'CAIXA ECONOMICA FEDERAL',
      ],
      'agencia' => '25',
      'conta' => '7319443555',
      'valorBase' => 1621,
      'margemConsignavel' => 567.35,
      'margemUtilizadaEmprestimo' => 475.35,
      'margemDisponivelEmprestimo' => 81.05,
      'contratosEmprestimo' => [
        [
          'contrato' => '0266540545JMA',
          'banco' => [
            'codigo' => '753',
            'descricao' => 'NOVO BANCO CONTINENTAL S A',
          ],
          'quantidadeParcelas' => 96,
          'valorEmprestado' => 20695.71,
          'valorLiberado' => 20000,
          'valorParcela' => 475.35,
          'cetAnual' => 26.43,
          'taxaAnual' => 24.6,
          'taxaMensal' => 1.85,
          'iof' => 695.71,
          'situacao' => 'Ativo',
        ],
      ],
      'contratosRMC' => [],
      'contratosRCC' => [],
    ],
  ];

  $updated = $wpdb->update(
    $requestsTable,
    [
      'status' => 'completed',
      'response_json' => wp_json_encode($fakePayload, JSON_UNESCAPED_UNICODE),
      'completed_at' => current_time('mysql'),
      'updated_at' => current_time('mysql'),
      'error_code' => null,
      'error_message' => null,
    ],
    [
      'request_id' => $requestId,
      'service_slug' => 'inss',
    ]
  );

  if ($updated === false) {
    return acme_err(500, 'Falha ao finalizar requisição INSS.', 'DB_UPDATE_ERROR');
  }

  return acme_ok([
    'success' => true,
    'request_id' => $requestId,
    'status' => 'completed',
  ], 200);
}

function acme_inss_simulate_fail(WP_REST_Request $req)
{
  global $wpdb;

  $requestId = (string) ($req->get_param('request_id') ?? '');
  if ($requestId === '') {
    return acme_err(400, 'request_id obrigatório', 'MISSING_REQUEST_ID');
  }

  $requestsTable = $wpdb->prefix . 'service_requests';

  $row = $wpdb->get_row(
    $wpdb->prepare(
      "SELECT * FROM {$requestsTable}
       WHERE request_id = %s
         AND service_slug = %s
       LIMIT 1",
      $requestId,
      'inss'
    ),
    ARRAY_A
  );

  if (!$row) {
    return acme_err(404, 'Requisição INSS não encontrada', 'NOT_FOUND');
  }

  $updated = $wpdb->update(
    $requestsTable,
    [
      'status' => 'failed',
      'error_code' => 'SIMULATED_FAIL',
      'error_message' => 'Falha simulada da consulta INSS.',
      'completed_at' => current_time('mysql'),
      'updated_at' => current_time('mysql'),
    ],
    [
      'request_id' => $requestId,
      'service_slug' => 'inss',
    ]
  );

  if ($updated === false) {
    return acme_err(500, 'Falha ao atualizar requisição INSS.', 'DB_UPDATE_ERROR');
  }

  return acme_ok([
    'success' => true,
    'request_id' => $requestId,
    'status' => 'failed',
  ], 200);
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

if (!function_exists('acme_make_inss_request_id')) {
  function acme_make_inss_request_id(): string
  {
    return 'inss_' . substr(md5(uniqid('', true)), 0, 12);
  }
}

if (!function_exists('acme_mask_beneficio')) {
  function acme_mask_beneficio(string $beneficio): string
  {
    $beneficio = preg_replace('/\D+/', '', $beneficio);

    if ($beneficio === '') {
      return '';
    }

    if (strlen($beneficio) <= 4) {
      return str_repeat('*', strlen($beneficio));
    }

    return str_repeat('*', strlen($beneficio) - 4) . substr($beneficio, -4);
  }
}

if (!function_exists('acme_resolve_authenticated_user_id')) {
  function acme_resolve_authenticated_user_id(WP_REST_Request $req): int
  {
    $userId = (int) get_current_user_id();
    if ($userId > 0) {
      return $userId;
    }

    $apiKey = function_exists('acme_get_api_key_from_request')
      ? acme_get_api_key_from_request($req)
      : (string) $req->get_header('x-acme-key');
    if ($apiKey !== '' && function_exists('acme_validate_api_key')) {
      $consumerData = acme_validate_api_key($apiKey, 'inss');

      if (!is_wp_error($consumerData)) {
        return true;
      }
    }

    return 0;
  }
}

/* ============================================================
 * POST /api-inss
 * ============================================================
 */

function acme_api_inss_start(WP_REST_Request $req)
{
  global $wpdb;

  $params = $req->get_json_params();
  if (!is_array($params)) {
    $params = [];
  }

  $beneficio = preg_replace('/\D+/', '', (string) ($params['beneficio'] ?? ''));

  if ($beneficio === '') {
    return acme_err(400, 'Número do benefício obrigatório.', 'INVALID_BENEFICIO');
  }

  $userId = (int) get_current_user_id();

  if ($userId <= 0) {
    $authContext = function_exists('acme_resolve_authenticated_user_context')
      ? acme_resolve_authenticated_user_context($req)
      : acme_resolve_authenticated_user_id($req);

    if (is_wp_error($authContext)) {
      return acme_err(
        (int) ($authContext->get_error_data()['status'] ?? 401),
        $authContext->get_error_message(),
        strtoupper((string) $authContext->get_error_code())
      );
    }

    if (is_array($authContext)) {
      $userId = (int) ($authContext['user_id'] ?? 0);
    } else {
      $userId = (int) $authContext;
    }
  }

  if ($userId <= 0) {
    return acme_err(401, 'Você precisa estar logado ou informar uma API key válida.', 'NOT_AUTHENTICATED');
  }

  if (function_exists('acme_user_has_credit') && !acme_user_has_credit($userId, 'inss')) {
    return acme_err(402, 'Sem créditos', 'NO_CREDITS');
  }

  $inssBaseUrl = '';

  if (defined('ACME_INSS_API_BASE') && trim((string) ACME_INSS_API_BASE) !== '') {
    $inssBaseUrl = trim((string) ACME_INSS_API_BASE);
  } elseif (defined('ACME_INSS_BRIDGE_URL') && trim((string) ACME_INSS_BRIDGE_URL) !== '') {
    $inssBaseUrl = trim((string) ACME_INSS_BRIDGE_URL);
  }

  if ($inssBaseUrl === '') {
    return acme_err(500, 'API do fornecedor INSS não configurada.', 'INSS_API_NOT_CONFIGURED');
  }

  $requestId = acme_make_inss_request_id();
  $requestsTable = $wpdb->prefix . 'service_requests';

  $serviceId = function_exists('acme_get_service_id_by_slug')
    ? (int) acme_get_service_id_by_slug('inss')
    : 0;

  $userCredits = ($serviceId > 0 && function_exists('acme_credit_balance_user'))
    ? (int) acme_credit_balance_user($userId, $serviceId)
    : 0;

  $providerUrl = rtrim($inssBaseUrl, '/') . '/' . rawurlencode($beneficio);

  $inserted = $wpdb->insert(
    $requestsTable,
    [
      'user_id'             => $userId,
      'request_id'          => $requestId,
      'clt_request_id'      => wp_generate_uuid4(),
      'provider_request_id' => null,
      'service_slug'        => 'inss',
      'cpf_hash'            => hash('sha256', $beneficio),
      'cpf_masked'          => acme_mask_beneficio($beneficio),
      'webhook_url'         => '',
      'status'              => 'pending',
      'created_at'          => current_time('mysql'),
      'updated_at'          => current_time('mysql'),
      'numeroDocumento'                 => $beneficio,

    ]
  );

  if (!$inserted) {
    return acme_err(500, 'Falha ao criar requisição INSS.', 'DB_ERROR');
  }

  $response = wp_remote_get($providerUrl, [
    'timeout' => 20,
    'headers' => [
      'Accept' => 'application/json',
    ],
  ]);

  if (is_wp_error($response)) {

    $wpdb->update(
      $requestsTable,
      [
        'status'        => 'failed',
        'error_code'    => 'HTTP_ERROR',
        'error_message' => $response->get_error_message(),
        'completed_at'  => current_time('mysql'),
        'updated_at'    => current_time('mysql'),
      ],
      [
        'request_id'   => $requestId,
        'service_slug' => 'inss',
      ]
    );

    return acme_err(
      502,
      'Erro ao consultar fornecedor INSS.',
      'HTTP_ERROR'
    );
  }

  $statusCode   = (int) wp_remote_retrieve_response_code($response);
  $responseBody = (string) wp_remote_retrieve_body($response);

  if ($statusCode !== 200 || $responseBody === '') {

    $wpdb->update(
      $requestsTable,
      [
        'status'        => 'failed',
        'error_code'    => 'BAD_STATUS',
        'error_message' => 'Fornecedor respondeu HTTP ' . $statusCode,
        'response'      => $responseBody,
        'completed_at'  => current_time('mysql'),
        'updated_at'    => current_time('mysql'),
      ],
      [
        'request_id'   => $requestId,
        'service_slug' => 'inss',
      ]
    );

    return acme_err(
      502,
      'Fornecedor indisponível.',
      'BAD_STATUS'
    );
  }

  $decodedResponse = json_decode($responseBody, true);

  if (!is_array($decodedResponse)) {

    $wpdb->update(
      $requestsTable,
      [
        'status'        => 'failed',
        'error_code'    => 'INVALID_JSON',
        'error_message' => 'Resposta inválida do fornecedor',
        'response'      => $responseBody,
        'completed_at'  => current_time('mysql'),
        'updated_at'    => current_time('mysql'),
      ],
      [
        'request_id'   => $requestId,
        'service_slug' => 'inss',
      ]
    );

    return acme_err(
      502,
      'Resposta inválida do fornecedor.',
      'INVALID_JSON'
    );
  }

  //
  // ✔ BENEFÍCIO INVÁLIDO
  //

  $providerMessage = '';

  if (!empty($decodedResponse['message'])) {
    $providerMessage = (string) $decodedResponse['message'];
  }

  if (!empty($decodedResponse['mensagem'])) {
    $providerMessage = (string) $decodedResponse['mensagem'];
  }

  if ($providerMessage !== '') {

    $wpdb->update(
      $requestsTable,
      [
        'status'        => 'failed',
        'error_code'    => 'PROVIDER_ERROR',
        'error_message' => $providerMessage,
        'response'      => $responseBody,
        'completed_at'  => current_time('mysql'),
        'updated_at'    => current_time('mysql'),
      ],
      [
        'request_id'   => $requestId,
        'service_slug' => 'inss',
      ]
    );

    return acme_err(
      400,
      $providerMessage,
      'PROVIDER_ERROR'
    );
  }

  //
  // ✔ SUCESSO COM DADOS
  //

  if (empty($decodedResponse['dados'])) {

    $wpdb->update(
      $requestsTable,
      [
        'status'        => 'failed',
        'error_code'    => 'NO_DATA',
        'error_message' => 'Fornecedor não retornou dados.',
        'response'      => $responseBody,
        'completed_at'  => current_time('mysql'),
        'updated_at'    => current_time('mysql'),
      ],
      [
        'request_id'   => $requestId,
        'service_slug' => 'inss',
      ]
    );

    return acme_err(
      502,
      'Fornecedor não retornou dados.',
      'NO_DATA'
    );
  }

  //
  // ✔ DEBITAR CRÉDITO AQUI
  //

  // Debitar crédito aqui usando o mesmo fluxo do CLT,
  // buscando o custo do serviço "inss" na tabela de serviços.

  if ($userId > 0 && function_exists('acme_consume_credit') && function_exists('acme_get_service_credit_cost')) {
    $cost = (int) acme_get_service_credit_cost('inss');

    if ($cost > 0) {
      acme_consume_credit($userId, 'inss', $cost, $requestId);
    }
  }

  $wpdb->update(
    $requestsTable,
    [
      'status'        => 'completed',
      'response_json' => $responseBody,
      'response'      => $responseBody,
      'completed_at'  => current_time('mysql'),
      'updated_at'    => current_time('mysql'),
      'error_code'    => null,
      'error_message' => null,
    ],
    [
      'request_id'   => $requestId,
      'service_slug' => 'inss',
    ]
  );

  return acme_ok([
    'success' => true,
    'message' => 'Consulta INSS concluída.',
    'data'    => [
      'request_id'    => $requestId,
      'status'        => 'completed',
      'response_data' => $decodedResponse,
    ],
  ], 200);
}
/* ============================================================
 * GET /api-inss-status
 * ============================================================
 */

function acme_api_inss_status(WP_REST_Request $req)
{
  global $wpdb;

  $requestId = (string) ($req->get_param('request_id') ?? '');
  if ($requestId === '') {
    return acme_err(400, 'request_id obrigatório', 'MISSING_REQUEST_ID');
  }

  $userId = acme_resolve_authenticated_user_id($req);
  if ($userId <= 0) {
    return acme_err(401, 'Você precisa estar logado ou informar uma API key válida.', 'NOT_AUTHENTICATED');
  }

  $requestsTable = $wpdb->prefix . 'service_requests';

  $row = $wpdb->get_row(
    $wpdb->prepare(
      "SELECT *
             FROM {$requestsTable}
             WHERE request_id = %s
               AND service_slug = %s
             LIMIT 1",
      $requestId,
      'inss'
    ),
    ARRAY_A
  );

  if (!$row) {
    return acme_err(404, 'Requisição INSS não encontrada', 'NOT_FOUND');
  }

  if (!current_user_can('manage_options') && (int) $row['user_id'] !== $userId) {
    return acme_err(403, 'Você não tem permissão para consultar esta requisição.', 'REQUEST_NOT_OWNED_BY_USER');
  }

  $responseData = null;
  if (!empty($row['response_json'])) {
    $decodedResponse = json_decode($row['response_json'], true);
    $responseData = is_array($decodedResponse) ? $decodedResponse : null;
  }

  $data = [
    'request_id'          => (string) $row['request_id'],
    'provider_request_id' => $row['provider_request_id'] ?? null,
    'beneficio'           => (string) ($row['cpf_masked'] ?? ''),
    'status'              => (string) ($row['status'] ?? 'pending'),
    'created_at'          => $row['created_at'] ?? null,
    'updated_at'          => $row['updated_at'] ?? null,
    'completed_at'        => $row['completed_at'] ?? null,
  ];

  if ($row['status'] === 'completed') {
    $data['response_data'] = $responseData;
    $data['message'] = 'Consulta INSS concluída.';
  }

  if ($row['status'] === 'failed') {
    $data['error'] = [
      'code' => $row['error_code'] ?: 'FAILED',
      'message' => $row['error_message'] ?: 'Houve um erro no processamento da consulta.',
    ];
  }

  return acme_ok([
    'success' => true,
    'data'    => $data,
  ], 200);
}


add_action(
  'acme_inss_real_dispatch',
  'acme_inss_real_dispatch_handler',
  10,
  3
);

function acme_inss_real_dispatch_handler(
  string $requestId,
  string $beneficio,
  $webhookUrl
) {
  global $wpdb;

  $requestsTable = $wpdb->prefix . 'service_requests';

  if (!defined('ACME_INSS_API_BASE')) {
    error_log('[ACME INSS] API base não configurada');
    return;
  }

  $url =
    rtrim(ACME_INSS_API_BASE, '/') .
    '/' .
    urlencode($beneficio);

  error_log('[ACME INSS] GET ' . $url);

  $response = wp_remote_get($url, [
    'timeout' => 20,
  ]);

  if (is_wp_error($response)) {

    $wpdb->update(
      $requestsTable,
      [
        'status' => 'failed',
        'error_code' => 'HTTP_ERROR',
        'error_message' => $response->get_error_message(),
        'completed_at' => current_time('mysql'),
        'updated_at' => current_time('mysql'),
      ],
      [
        'request_id' => $requestId,
        'service_slug' => 'inss',
      ]
    );

    return;
  }

  $status = wp_remote_retrieve_response_code($response);
  $body   = wp_remote_retrieve_body($response);

  if ($status !== 200 || empty($body)) {

    $wpdb->update(
      $requestsTable,
      [
        'status' => 'failed',
        'error_code' => 'BAD_STATUS',
        'error_message' => 'HTTP ' . $status,
        'response' => $body,
        'completed_at' => current_time('mysql'),
        'updated_at' => current_time('mysql'),
      ],
      [
        'request_id' => $requestId,
        'service_slug' => 'inss',
      ]
    );

    return;
  }

  // sucesso

  $wpdb->update(
    $requestsTable,
    [
      'status' => 'completed',
      'response_json' => $body,
      'completed_at' => current_time('mysql'),
      'updated_at' => current_time('mysql'),
    ],
    [
      'request_id' => $requestId,
      'service_slug' => 'inss',
    ]
  );

  error_log('[ACME INSS] completed ' . $requestId);
}


if (!function_exists('acme_resolve_authenticated_user_context')) {
  function acme_resolve_authenticated_user_context(WP_REST_Request $req)
  {
    $currentUserId = (int) get_current_user_id();
    if ($currentUserId > 0) {
      return [
        'user_id' => $currentUserId,
        'source'  => 'session',
      ];
    }

    $apiKey = function_exists('acme_get_api_key_from_request')
      ? acme_get_api_key_from_request($req)
      : (string) $req->get_header('x-acme-key');

    if ($apiKey === '') {
      return new WP_Error(
        'acme_api_key_required',
        'A chave da API é obrigatória.',
        ['status' => 401]
      );
    }

    if (!function_exists('acme_validate_api_key')) {
      return new WP_Error(
        'acme_api_key_validator_missing',
        'Validador da API pública indisponível.',
        ['status' => 500]
      );
    }

    $consumerData = acme_validate_api_key($apiKey, 'inss');
    if (is_wp_error($consumerData)) {
      return $consumerData;
    }

    $resolvedUserId = (int) ($consumerData['wp_user_id'] ?? 0);
    if ($resolvedUserId <= 0) {
      return new WP_Error(
        'acme_api_key_user_invalid',
        'Usuário vinculado à chave é inválido.',
        ['status' => 401]
      );
    }

    return [
      'user_id' => $resolvedUserId,
      'source'  => 'api_key',
      'consumer' => $consumerData,
    ];
  }
}
