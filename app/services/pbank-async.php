<?php
if (!defined('ABSPATH'))
    exit;

/**
 * ACME Presença Bank (bridge)
 * POST /wp-json/acme/v1/pb-vinculo
 * body: { cpf: "..." }
 */

function acme_pb_fake_nome(): string
{
    // simples e “humano o suficiente” para testes
    $nomes = ['Maria', 'Ana', 'Joao', 'Carlos', 'Paula', 'Marcos', 'Fernanda', 'Juliana', 'Pedro', 'Rafaela'];
    $sobrenomes = ['Silva', 'Santos', 'Oliveira', 'Souza', 'Pereira', 'Costa', 'Rodrigues', 'Almeida', 'Nunes', 'Lima'];
    return $nomes[array_rand($nomes)] . ' ' . $sobrenomes[array_rand($sobrenomes)];
}

function acme_pb_fake_telefone(): string
{
    // 11 dígitos BR (DDD 11 + celular)
    $ddd = '11';
    $n = '9' . str_pad((string) random_int(10000000, 99999999), 8, '0', STR_PAD_LEFT);
    return $ddd . $n;
}

add_action('rest_api_init', function () {
    register_rest_route('acme/v1', '/pb-vinculo', [
        'methods' => 'POST',


        /*'permission_callback' => function () {
          return is_user_logged_in(); // ajuste se quiser exigir admin
        },*/
        'permission_callback' => function (\WP_REST_Request $req) {

            // 1) Se estiver logada, ok
            if (is_user_logged_in()) {
                return true;
            }

            // 2) Permitir testes apenas no localhost
            $ip = $_SERVER['REMOTE_ADDR'] ?? '';
            if (in_array($ip, ['127.0.0.1', '::1'], true)) {
                return true;
            }

            // 3) Permitir por chave local de teste (legado)
            $localKey = (string) $req->get_header('x-acme-key');
            $expectedLocal = defined('ACME_LOCAL_TEST_KEY') ? (string) ACME_LOCAL_TEST_KEY : '';
            if ($expectedLocal !== '' && hash_equals($expectedLocal, $localKey)) {
                return true;
            }

            // 4) Permitir por chave interna do bridge PB (produção/homolog)
            $internalKey = (string) $req->get_header('x-internal-key');
            $expectedInternal = defined('ACME_PB_INTERNAL_KEY') ? (string) ACME_PB_INTERNAL_KEY : '';
            if ($expectedInternal !== '' && hash_equals($expectedInternal, $internalKey)) {
                return true;
            }

            return false;
        },



        'callback' => function (\WP_REST_Request $req) {


            #$cpf = preg_replace('/\D/', '', (string) ($req->get_param('cpf') ?? ''));
            $body = $req->get_json_params();
            $cpf_raw = $body['cpf'] ?? $req->get_param('cpf') ?? '';
            $cpf = preg_replace('/\D/', '', (string) $cpf_raw);

            if (strlen($cpf) !== 11) {
                return new \WP_REST_Response([
                    'ok' => false,
                    'error' => 'cpf_invalid',
                    'debug' => [
                        'received_param' => $req->get_param('cpf'),
                        'json_params' => $req->get_json_params(),
                        'cpf_after_clean' => $cpf,
                        'len' => strlen($cpf),
                    ]
                ], 400);
            }


            if (strlen($cpf) !== 11) {
                return new \WP_REST_Response(['ok' => false, 'error' => 'cpf_invalid'], 400);
            }

            // Bridge (acme-services). Configure via wp-config.php para ambientes diferentes.
            // Ex:
            // define('ACME_PB_BRIDGE_URL', 'http://127.0.0.1:31827/pb/credito-privado/consulta');
            // ou, se quiser só o vínculo:
            // define('ACME_PB_BRIDGE_URL', 'http://127.0.0.1:31827/pb/clt/vinculo');
            $bridge = defined('ACME_PB_BRIDGE_URL') && trim((string) ACME_PB_BRIDGE_URL) !== ''
                ? (string) ACME_PB_BRIDGE_URL
                : 'http://127.0.0.1:31827/pb/credito-privado/consulta';

            // Chave simples (a mesma do micro-serviço)
            $internalKey = defined('ACME_PB_INTERNAL_KEY') ? ACME_PB_INTERNAL_KEY : '';

            $payload = [
                'cpf' => $cpf,
                'nome' => acme_pb_fake_nome(),
                'telefone' => acme_pb_fake_telefone(),
                'produtoId' => 28,
            ];

            $resp = wp_remote_post($bridge, [
                'timeout' => 35,
                'headers' => array_filter([
                    'Content-Type' => 'application/json',
                    'X-Internal-Key' => $internalKey ?: null,
                ]),
                'body' => wp_json_encode($payload),
            ]);

            if (is_wp_error($resp)) {
                return new \WP_REST_Response(['ok' => false, 'error' => $resp->get_error_message()], 500);
            }

            $http = (int) wp_remote_retrieve_response_code($resp);
            $raw = (string) wp_remote_retrieve_body($resp);
            $json = json_decode($raw, true);

            return new \WP_REST_Response([
                'ok' => ($http >= 200 && $http < 300),
                'http' => $http,
                'data' => is_array($json) ? $json : null,
                'raw' => is_array($json) ? null : mb_substr($raw, 0, 1200),
                'sent' => ['cpf' => $cpf, 'nome' => $payload['nome'], 'telefone' => $payload['telefone'], 'produtoId' => 28],
            ], 200);
        }
    ]);
});
