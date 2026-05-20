<?php

declare(strict_types=1);

/**
 * Configuração central de constantes do plugin ACME.
 *
 * Este arquivo existe para tirar configurações sensíveis e caminhos do
 * bootstrap principal. A regra de compatibilidade é simples: nenhuma constante
 * antiga é renomeada. Se o site já definiu algum valor em wp-config.php, ele é
 * preservado porque cada definição usa `defined()` antes de declarar.
 *
 * RISCO:
 * Algumas integrações externas dependem dos nomes antigos das constantes.
 * Portanto, alterar esses nomes quebraria chamadas para APIs, geração de PDF e
 * fluxos assíncronos. Qualquer mudança futura deve criar alias ou camada de
 * compatibilidade antes de remover a constante original.
 */

if (!defined('ACME_ACC_PATH')) {
    define('ACME_ACC_PATH', plugin_dir_path(ACME_PLUGIN_FILE));
}

if (!defined('ACME_ACC_URL')) {
    define('ACME_ACC_URL', plugin_dir_url(ACME_PLUGIN_FILE));
}

if (!defined('ACME_PLUGIN_DIR')) {
    define('ACME_PLUGIN_DIR', ACME_ACC_PATH);
}

if (!defined('ACME_DEBUG')) {
    define('ACME_DEBUG', false);
}

/**
 * Quando verdadeiro, o módulo CLT evita chamada real para o provider.
 * Mantemos o valor padrão antigo para preservar o comportamento em produção.
 */
if (!defined('ACME_CLT_MOCK')) {
    define('ACME_CLT_MOCK', false);
}

if (!defined('ACME_CLT_MOCK_TOKEN')) {
    define('ACME_CLT_MOCK_TOKEN', 'mock_token_local');
}

/**
 * Endpoints legados usados pelos controllers assíncronos.
 *
 * IMPORTANTE:
 * Os valores continuam aqui para compatibilidade, mas a prática recomendada é
 * sobrescrevê-los no wp-config.php ou por variáveis de ambiente no deploy.
 */
if (!defined('ACME_CLT_API_BASE')) {
    define('ACME_CLT_API_BASE', 'https://teioemxjgepzvpcpevyi.supabase.co/functions/v1');
}

if (!defined('ACME_CLT_API_KEY')) {
    define('ACME_CLT_API_KEY', 'mcs_LaT9SiuPPJz5rpBZBmBbsJfEuY3XFtcNV9vNou4xWJ6q4l1p');
}

if (!defined('ACME_PB_INTERNAL_KEY')) {
    define('ACME_PB_INTERNAL_KEY', 'MINHA_CHAVE_SECRETA_123');
}

if (!defined('ACME_CLT_BRIDGE_URL')) {
    define('ACME_CLT_BRIDGE_URL', 'https://api.maiscorban.net/pb/credito-privado/enqueue');
}

if (!defined('ACME_CLT_BRIDGE_KEY')) {
    define('ACME_CLT_BRIDGE_KEY', 'f54oiuwqhbsncmn487924367jhf');
}

if (!defined('ACME_PB_BRIDGE_URL')) {
    define('ACME_PB_BRIDGE_URL', 'https://api.maiscorban.net/pb/credito-privado/consulta');
}

if (!defined('ACME_INSS_BRIDGE_URL')) {
    define('ACME_INSS_BRIDGE_URL', 'https://novaeraapp.b-cdn.net/v1/consultav2/94de3edb-7082-4810-9727-4dbe243b8fff/');
}

if (!defined('ACME_INSS_BRIDGE_KEY')) {
    define('ACME_INSS_BRIDGE_KEY', 'dev-key');
}

if (!defined('ACME_USE_TCPDF_INSS')) {
    define('ACME_USE_TCPDF_INSS', true);
}
