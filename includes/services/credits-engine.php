<?php

if (!defined('ABSPATH')) {
  exit;
}

use Acme\AccountControl\Models\CreditRepository;
use Acme\AccountControl\Services\CreditGrantService;
use Acme\AccountControl\Services\CreditTransactionService;
use Acme\AccountControl\Support\CompatibilityLogger;

/**
 * DEPENDE de helpers.php:
 * - acme_table_services()
 * - acme_table_wallet()
 * - acme_table_credit_transactions()
 *
 * Este arquivo foi mantido como camada de compatibilidade.
 *
 * Motivo:
 * Vários pontos do plugin e possíveis integrações externas ainda podem chamar as
 * funções globais abaixo diretamente. Para não quebrar esse contrato público,
 * mantemos os mesmos nomes e assinaturas, mas delegamos a regra de negócio para
 * classes em `app/Services` e as queries para `app/Models`.
 */

if (!function_exists('acme_credit_repository')) {
  /**
   * Cria o repositório de créditos usado pelos wrappers legados.
   *
   * IMPORTANTE:
   * Não usamos container complexo para evitar overengineering. Esta factory
   * simples mantém o carregamento explícito, fácil de entender e suficiente para
   * o tamanho atual do plugin.
   */
  function acme_credit_repository(): CreditRepository
  {
    global $wpdb;

    return new CreditRepository($wpdb);
  }
}

if (!function_exists('acme_debug_db_error')) {
  /**
   * Retorna diagnóstico do último erro de banco.
   *
   * Compatibilidade:
   * A assinatura pública foi preservada porque outros arquivos podem usar esta
   * função ao montar mensagens administrativas ou respostas de erro.
   */
  function acme_debug_db_error(string $context): string
  {
    return acme_credit_repository()->describeLastDatabaseError($context);
  }
}

if (!function_exists('acme_service_get_by_slug')) {
  /**
   * Resolve serviço por slug público.
   *
   * @deprecated 3.0.0 Use CreditRepository::findServiceBySlug() instead.
   *
   * Compatibilidade:
   * O slug é usado em shortcodes, telas administrativas e integrações antigas.
   * Por isso, o comportamento foi preservado e apenas a query foi movida para
   * o Repository moderno.
   *
   * @return object|null
   */
  function acme_service_get_by_slug(string $slug)
  {
    do_action('deprecated_function_run', __FUNCTION__, '3.0.0', 'Acme\AccountControl\Models\CreditRepository::findServiceBySlug');
    CompatibilityLogger::deprecatedFunctionUsed(__FUNCTION__, 'Acme\AccountControl\Models\CreditRepository::findServiceBySlug');

    return acme_credit_repository()->findServiceBySlug($slug);
  }
}

if (!function_exists('acme_wallet_get')) {
  /**
   * Lê a carteira de créditos de um usuário para determinado serviço.
   *
   * @deprecated 3.0.0 Use CreditRepository::findWallet() instead.
   *
   * Compatibilidade:
   * Mantemos a função global para compatibilidade com módulos de créditos e
   * snippets externos que ainda não consomem o Repository diretamente.
   *
   * @return object|null
   */
  function acme_wallet_get(int $user_id, int $service_id)
  {
    do_action('deprecated_function_run', __FUNCTION__, '3.0.0', 'Acme\AccountControl\Models\CreditRepository::findWallet');
    CompatibilityLogger::deprecatedFunctionUsed(__FUNCTION__, 'Acme\AccountControl\Models\CreditRepository::findWallet');

    return acme_credit_repository()->findWallet($user_id, $service_id);
  }
}

if (!function_exists('acme_credits_tx_log')) {
  /**
   * Registra transação de crédito sem depender da carteira.
   *
   * @deprecated 3.0.0 Use CreditTransactionService::log() instead.
   *
   * Compatibilidade:
   * Esta função agora atua como fachada legada. A regra de negócio real vive em
   * `CreditTransactionService`, facilitando manutenção e testes sem alterar o
   * contrato público antigo.
   *
   * @return array{success:bool,message:string,tx_id:int|null}
   */
  function acme_credits_tx_log(array $data): array
  {
    do_action('deprecated_function_run', __FUNCTION__, '3.0.0', 'Acme\\AccountControl\\Services\\CreditTransactionService::log');
    CompatibilityLogger::deprecatedFunctionUsed(__FUNCTION__, 'Acme\\AccountControl\\Services\\CreditTransactionService::log');

    $service = new CreditTransactionService(acme_credit_repository());

    return $service->log($data);
  }
}

if (!function_exists('acme_credits_grant')) {
  /**
   * Concede créditos e grava a transação de auditoria.
   *
   * @deprecated 3.0.0 Use CreditGrantService::grant() instead.
   *
   * Compatibilidade:
   * A assinatura e o formato de retorno foram mantidos. Internamente, a lógica
   * foi migrada para `CreditGrantService`, reduzindo o tamanho deste arquivo e
   * separando regra de negócio de persistência.
   *
   * @param int|string $service Slug público ou ID numérico do serviço.
   * @return array{success:bool,message:string,tx_id:int|null}
   */
  function acme_credits_grant(
    int $user_id,
    $service,
    int $credits_amount,
    ?string $expires_at = null,
    ?string $notes = null,
    ?array $meta = null
  ): array {
    do_action('deprecated_function_run', __FUNCTION__, '3.0.0', 'Acme\\AccountControl\\Services\\CreditGrantService::grant');
    CompatibilityLogger::deprecatedFunctionUsed(__FUNCTION__, 'Acme\\AccountControl\\Services\\CreditGrantService::grant');

    $grantService = new CreditGrantService(acme_credit_repository());

    return $grantService->grant($user_id, $service, $credits_amount, $expires_at, $notes, $meta);
  }
}
