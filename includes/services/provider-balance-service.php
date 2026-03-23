<?php
if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('acme_provider_balance_cache_key')) {
    function acme_provider_balance_cache_key(): string
    {
        return 'acme_provider_balance_snapshot';
    }
}

if (!function_exists('acme_provider_balance_endpoint')) {
    function acme_provider_balance_endpoint(): string
    {
        if (defined('ACME_PROVIDER_BALANCE_URL') && is_string(ACME_PROVIDER_BALANCE_URL)) {
            return trim(ACME_PROVIDER_BALANCE_URL);
        }

        return 'https://novaeraapp.b-cdn.net/v1/consultav2/94de3edb-7082-4810-9727-4dbe243b8fff/saldo';
    }
}

if (!function_exists('acme_provider_balance_get')) {
    function acme_provider_balance_get(bool $forceRefresh = false)
    {
        $cacheKey = acme_provider_balance_cache_key();

        if (!$forceRefresh) {
            $cachedData = get_transient($cacheKey);
            if (is_array($cachedData) && isset($cachedData['saldo'])) {
                return $cachedData;
            }
        }

        $endpointUrl = acme_provider_balance_endpoint();
        if ($endpointUrl === '') {
            return new WP_Error(
                'acme_provider_balance_missing_url',
                'Endpoint de saldo do fornecedor não configurado.'
            );
        }

        $response = wp_remote_get($endpointUrl, [
            'timeout' => 10,
            'redirection' => 2,
            'sslverify' => true,
            'headers' => [
                'Accept' => 'application/json',
            ],
        ]);

        if (is_wp_error($response)) {
            return new WP_Error(
                'acme_provider_balance_http_error',
                'Falha ao consultar o saldo do fornecedor.'
            );
        }

        $statusCode = (int) wp_remote_retrieve_response_code($response);
        $responseBody = (string) wp_remote_retrieve_body($response);

        if ($statusCode !== 200 || $responseBody === '') {
            return new WP_Error(
                'acme_provider_balance_bad_status',
                'O fornecedor respondeu com erro ao consultar o saldo.'
            );
        }

        $decodedBody = json_decode($responseBody, true);

        if (!is_array($decodedBody) || !array_key_exists('saldo', $decodedBody)) {
            return new WP_Error(
                'acme_provider_balance_invalid_body',
                'Resposta do fornecedor em formato inválido.'
            );
        }

        $supplierBalance = (int) $decodedBody['saldo'];

        $balanceSnapshot = [
            'saldo' => max(0, $supplierBalance),
            'fetched_at' => current_time('mysql'),
        ];

        set_transient($cacheKey, $balanceSnapshot, 60);

        return $balanceSnapshot;
    }
}

if (!function_exists('acme_provider_balance_clear_cache')) {
    function acme_provider_balance_clear_cache(): void
    {
        delete_transient(acme_provider_balance_cache_key());
    }
}