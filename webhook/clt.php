<?php

define('SHORTINIT', true);
require_once $_SERVER['DOCUMENT_ROOT'] . '/wp-load.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error'=>'POST only']);
    exit;
}

// token simples só para teste local
$secret = 'acme_local_webhook';

$token = $_SERVER['HTTP_X_WEBHOOK_TOKEN'] ?? '';

if ($token !== $secret) {
    http_response_code(401);
    echo json_encode(['error'=>'unauthorized']);
    exit;
}

$cpf = preg_replace('/\D/', '', $_POST['cpf'] ?? '');

if (!$cpf) {
    echo json_encode(['error'=>'cpf required']);
    exit;
}

// usuário fixo teste
$user_id = get_current_user_id();

if (!$user_id) {
    echo json_encode(['error'=>'user not logged']);
    exit;
}

require_once WP_PLUGIN_DIR.'/acme-account-control/services/clt-provider.php';

$result = acme_service_clt($user_id, $cpf);

echo json_encode($result);
