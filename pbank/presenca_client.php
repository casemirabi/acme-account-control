<?php
if (!defined('ABSPATH'))
    exit;

/**
 * Presença Bank — Client REST + SignalR (Azure)
 * - login (token JWT)
 * - request genérico (REST)
 * - negotiate do hub no presenca-bank-api
 * - negotiate do Azure SignalR (pega connectionId/connectionToken)
 * - connect via LongPolling (id obrigatório)
 */

if (!function_exists('acme_pb_login')) {
    function acme_pb_login(string $login, string $senha): array
    {
        $url = 'https://presenca-bank-api.azurewebsites.net/login';

        $resp = wp_remote_post($url, [
            'timeout' => 20,
            'headers' => [
                'Accept' => 'application/json, text/plain, */*',
                'Content-Type' => 'application/json',
                'Origin' => 'https://portal.presencabank.com.br',
                'Referer' => 'https://portal.presencabank.com.br/',
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36',
            ],
            'body' => wp_json_encode(['login' => $login, 'senha' => $senha]),
        ]);

        if (is_wp_error($resp)) {
            return ['ok' => false, 'error' => $resp->get_error_message()];
        }

        $http = (int) wp_remote_retrieve_response_code($resp);
        $raw = (string) wp_remote_retrieve_body($resp);
        $json = json_decode($raw, true);

        if ($http < 200 || $http >= 300 || !is_array($json) || empty($json['token'])) {
            return ['ok' => false, 'http' => $http, 'raw' => mb_substr($raw, 0, 800)];
        }

        return [
            'ok' => true,
            'http' => $http,
            'token' => $json['token'],
            'expireAt' => $json['expireAt'] ?? null,
            'usuario' => $json['usuario'] ?? null,
        ];
    }
}

if (!function_exists('acme_pb_request')) {
    function acme_pb_request(string $method, string $base_url, string $path, string $token, array $payload = null): array
    {
        $base_url = rtrim($base_url, '/');
        $path = '/' . ltrim($path, '/');
        $url = $base_url . $path;

        $args = [
            'method' => strtoupper($method),
            'timeout' => 25,
            'headers' => [
                'Accept' => 'application/json, text/plain, */*',
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $token,
                'Origin' => 'https://portal.presencabank.com.br',
                'Referer' => 'https://portal.presencabank.com.br/',
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36',
            ],
        ];

        if ($payload !== null) {
            $args['body'] = wp_json_encode($payload);
        }

        $resp = wp_remote_request($url, $args);

        if (is_wp_error($resp)) {
            return ['ok' => false, 'error' => $resp->get_error_message()];
        }

        $http = (int) wp_remote_retrieve_response_code($resp);
        $raw = (string) wp_remote_retrieve_body($resp);
        $json = json_decode($raw, true);

        return [
            'ok' => ($http >= 200 && $http < 300),
            'http' => $http,
            'data' => is_array($json) ? $json : null,
            'raw' => is_array($json) ? null : mb_substr($raw, 0, 1200),
        ];
    }
}

if (!function_exists('acme_pb_signalr_negotiate_azure')) {
    function acme_pb_signalr_negotiate_azure(array $negotiate): array
    {
        $url = $negotiate['url'] ?? '';
        $token = $negotiate['accessToken'] ?? '';

        if (!$url || !$token) {
            return ['ok' => false, 'error' => 'missing_url_or_accessToken'];
        }

        // A URL vem assim:
        // https://signalrpb.service.signalr.net/client/?hub=consultabeneficiohub&...
        // O negotiate certo é:
        // https://signalrpb.service.signalr.net/client/negotiate?hub=consultabeneficiohub&...
        $negotiateUrl = str_replace('/client/?', '/client/negotiate?', $url);

        // garantir negotiateVersion (se já tiver, não duplica)
        if (stripos($negotiateUrl, 'negotiateVersion=') === false) {
            $negotiateUrl .= '&negotiateVersion=1';
        }

        $resp = wp_remote_post($negotiateUrl, [
            'timeout' => 25,
            'headers' => [
                'Accept' => 'application/json, text/plain, */*',
                'Content-Type' => 'text/plain',
                'Authorization' => 'Bearer ' . $token,
                'Origin' => 'https://portal.presencabank.com.br',
                'Referer' => 'https://portal.presencabank.com.br/',
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36',
                'x-signalr-user-agent' => 'Microsoft SignalR/8.0 (8.0.0; Unknown OS; Browser; Unknown Runtime Version)',
            ],
            'body' => '',
        ]);

        if (is_wp_error($resp)) {
            return ['ok' => false, 'error' => $resp->get_error_message()];
        }

        $http = (int) wp_remote_retrieve_response_code($resp);
        $raw = (string) wp_remote_retrieve_body($resp);
        $json = json_decode($raw, true);

        return [
            'ok' => ($http >= 200 && $http < 300 && is_array($json)),
            'http' => $http,
            'data' => is_array($json) ? $json : null,
            'raw' => is_array($json) ? null : mb_substr($raw, 0, 1200),
        ];
    }

}

if (!function_exists('acme_pb_signalr_connect')) {
    function acme_pb_signalr_connect(array $negotiate, array $azNeg): array
    {
        $url = $negotiate['url'] ?? '';
        $token = $negotiate['accessToken'] ?? '';

        $id = $azNeg['connectionToken'] ?? ($azNeg['connectionId'] ?? '');

        if (!$url || !$token || !$id) {
            return ['ok' => false, 'error' => 'missing_url_token_or_connection_id'];
        }

        // LongPolling exige id
        $connectUrl = $url . '&transport=longPolling&id=' . rawurlencode($id);

        $resp = wp_remote_get($connectUrl, [
            'timeout' => 25,
            'headers' => [
                'Accept' => 'application/json, text/plain, */*',
                'Authorization' => 'Bearer ' . $token,
                'Origin' => 'https://portal.presencabank.com.br',
                'Referer' => 'https://portal.presencabank.com.br/',
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36',
                'x-signalr-user-agent' => 'Microsoft SignalR/8.0 (8.0.0; Unknown OS; Browser; Unknown Runtime Version)',
            ],
        ]);

        if (is_wp_error($resp)) {
            return ['ok' => false, 'error' => $resp->get_error_message()];
        }

        $http = (int) wp_remote_retrieve_response_code($resp);
        $raw = (string) wp_remote_retrieve_body($resp);

        return [
            'ok' => ($http >= 200 && $http < 300),
            'http' => $http,
            'raw' => mb_substr($raw, 0, 1200),
        ];
    }
}
