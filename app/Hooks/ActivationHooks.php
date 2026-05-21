<?php

declare(strict_types=1);

namespace Acme\AccountControl\Hooks;

use Acme\AccountControl\Models\ApiAccessLogRepository;
use Acme\AccountControl\Models\ServiceRequestRepository;

/**
 * Centraliza as rotinas executadas na ativação do plugin.
 *
 * Objetivo:
 * Manter `register_activation_hook` fora do bootstrap principal e documentar
 * quais módulos alteram banco de dados ou estado inicial do WordPress.
 *
 * Compatibilidade:
 * As funções chamadas aqui são as mesmas já existentes no plugin legado. Não
 * renomeamos nenhuma função para não quebrar integrações internas nem testes de
 * regressão.
 */
final class ActivationHooks
{
    /**
     * Registra o hook nativo de ativação do WordPress.
     *
     * @param string $pluginFile Arquivo principal reconhecido pelo WordPress.
     */
    public function register(string $pluginFile): void
    {
        register_activation_hook($pluginFile, [$this, 'activate']);
    }

    /**
     * Executa ativações dos módulos que criam tabelas ou dados iniciais.
     *
     * RISCO:
     * Essas chamadas podem executar `dbDelta()` e alterar schema. Por isso, a
     * ordem foi preservada em relação ao plugin original.
     */
    public function activate(): void
    {
        $activationFunctions = [
            'acme_services_activate',
            'acme_credit_allowances_activate',
            'acme_credit_contracts_activate',
            'acme_credit_lots_activate',
            'acme_users_activate',
            'acme_api_consumers_activate',
            'acme_clt_activate',
            'acme_inss_activate',
        ];

        foreach ($activationFunctions as $activationFunction) {
            if (function_exists($activationFunction)) {
                $activationFunction();
            }
        }

        // Atualizações incrementais usadas pela API de recursos e Login Master.
        // Mantém compatibilidade com tabelas existentes e adiciona apenas colunas/índices necessários.
        if (class_exists(ServiceRequestRepository::class)) {
            ServiceRequestRepository::activate();
        }

        if (class_exists(ApiAccessLogRepository::class)) {
            ApiAccessLogRepository::activate();
        }
    }
}
