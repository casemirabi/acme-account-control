<?php

declare(strict_types=1);

namespace Acme\AccountControl\Services;

use Acme\AccountControl\Models\CreditRepository;

/**
 * Serviço responsável por registrar transações de crédito.
 *
 * Objetivo:
 * Manter a regra de negócio de auditoria de créditos fora das funções globais e
 * fora dos controllers. Isso deixa o fluxo mais claro e facilita testes de
 * regressão sem alterar o contrato público do plugin.
 *
 * Compatibilidade:
 * A função global `acme_credits_tx_log()` continua existindo e agora apenas
 * delega para este Service. Assim, integrações antigas continuam funcionando.
 */
final class CreditTransactionService
{
    /** @var CreditRepository */
    private $creditRepository;

    public function __construct(CreditRepository $creditRepository)
    {
        $this->creditRepository = $creditRepository;
    }

    /**
     * Registra uma transação de crédito sem depender da carteira.
     *
     * Este fluxo é usado por contratos, lotes e relatórios. Por isso, os campos
     * e mensagens de retorno foram preservados para reduzir risco de quebra.
     *
     * @return array{success:bool,message:string,tx_id:int|null}
     */
    public function log(array $data): array
    {
        $now = current_time('mysql');

        // Preenche automaticamente identidade do serviço quando apenas o ID foi informado.
        if ((empty($data['service_slug']) || empty($data['service_name'])) && !empty($data['service_id'])) {
            $serviceId = (int) $data['service_id'];

            if ($serviceId > 0) {
                $serviceRow = $this->creditRepository->findServiceIdentityById($serviceId);

                if ($serviceRow) {
                    if (empty($data['service_slug'])) {
                        $data['service_slug'] = (string) $serviceRow->slug;
                    }

                    if (empty($data['service_name'])) {
                        $data['service_name'] = (string) $serviceRow->name;
                    }
                }
            }
        }

        $row = array_merge([
            'user_id' => 0,
            'service_id' => 0,
            'service_slug' => null,
            'service_name' => null,
            'type' => null,
            'credits' => 0,
            'status' => 'success',
            'attempts' => 1,
            'request_id' => null,
            'actor_user_id' => get_current_user_id(),
            'notes' => null,
            'meta' => null,
            'origin' => 'concession',
            'created_at' => $now,
            'wallet_total_before' => 0,
            'wallet_used_before' => 0,
            'wallet_total_after' => 0,
            'wallet_used_after' => 0,
        ], $data);

        $row['user_id'] = (int) $row['user_id'];
        $row['service_id'] = (int) $row['service_id'];
        $row['credits'] = (int) $row['credits'];
        $row['attempts'] = (int) $row['attempts'];
        $row['wallet_total_before'] = (int) ($row['wallet_total_before'] ?? 0);
        $row['wallet_used_before'] = (int) ($row['wallet_used_before'] ?? 0);
        $row['wallet_total_after'] = (int) ($row['wallet_total_after'] ?? 0);
        $row['wallet_used_after'] = (int) ($row['wallet_used_after'] ?? 0);

        if ($row['user_id'] <= 0 || $row['service_id'] <= 0 || $row['credits'] <= 0 || empty($row['type'])) {
            return ['success' => false, 'message' => 'Dados inválidos para registrar transação.', 'tx_id' => null];
        }

        // Normaliza metadados para JSON porque a tabela legado espera string.
        if (is_array($row['meta'])) {
            $row['meta'] = wp_json_encode($row['meta']);
        }

        $inserted = $this->creditRepository->insertCreditTransaction($row);

        if ($inserted === false) {
            return [
                'success' => false,
                'message' => $this->creditRepository->describeLastDatabaseError('Erro ao inserir transação (log puro)'),
                'tx_id' => null,
            ];
        }

        return [
            'success' => true,
            'message' => 'Transação registrada.',
            'tx_id' => $this->creditRepository->getLastInsertId(),
        ];
    }
}
