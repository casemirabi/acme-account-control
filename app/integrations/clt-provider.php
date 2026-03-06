<?php
if (!defined('ABSPATH'))
    exit;

function acme_service_clt($user_id, $cpf)
{
    global $wpdb;

    $service = 'clt';

    if (!acme_user_has_credit($user_id, $service)) {
        return ['error' => 'Sem créditos'];
    }

    // MOCK
    if (defined('ACME_CLT_MOCK') && ACME_CLT_MOCK) {

        $path = ACME_ACC_PATH . 'mock/clt-response-real.json';

        if (!file_exists($path)) {
            return ['error' => 'Mock JSON não encontrado: clt-response-real.json'];
        }

        $json = file_get_contents($path);
        $response = json_decode($json, true);

        if (!is_array($response)) {
            return ['error' => 'Mock JSON inválido'];
        }

        $token = defined('ACME_CLT_MOCK_TOKEN') ? ACME_CLT_MOCK_TOKEN : 'mock';

    } else {
        return ['error' => 'API real ainda não ativada'];
    }

    // Mascara CPF
    $cpf_masked = preg_replace('/(\d{3})(\d{3})(\d{3})(\d{2})/', '$1.$2.$3-$4', $cpf);

    // Salva resultado
    $wpdb->insert($wpdb->prefix . 'clt_results', [
        'user_id' => $user_id,
        'cpf_hash' => hash('sha256', $cpf),
        'cpf_masked' => $cpf_masked,
        'json_full' => json_encode($response),
        'created_at' => current_time('mysql')
    ]);

    $result_id = (int) $wpdb->insert_id;

    if (!$wpdb->insert_id) {
        return ['error' => 'Falha ao salvar clt_results: ' . $wpdb->last_error];
    }


    // Log API
    acme_log_api([
        'user_id' => $user_id,
        'service' => $service,
        'api' => 'CLT',
        'token' => $token,
        'status' => 'success'
    ]);

    // Debita crédito
    $debit = acme_consume_credit((int) $user_id, $service, 1);

    if (empty($debit['ok'])) {
        return ['error' => $debit['error'] ?? 'Falha ao debitar'];
    }

    // Retorna para o front
    /*return [
        'result_id' => $result_id,
        'items'     => $response
    ];*/

    return [
        'provider_version' => 'CLT_PROVIDER_V2',
        'result_id' => $result_id,
        'items' => $response
    ];



}
