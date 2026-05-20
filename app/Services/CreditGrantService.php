<?php

declare(strict_types=1);

namespace Acme\AccountControl\Services;

use Acme\AccountControl\Models\CreditRepository;

/**
 * Serviço responsável por conceder créditos a usuários.
 *
 * Objetivo:
 * Isolar a regra transacional de concessão de créditos em uma classe pequena e
 * explícita. Controllers e funções globais não devem conhecer detalhes de wallet,
 * transação SQL, auditoria ou normalização de metadados.
 *
 * Compatibilidade:
 * A função pública `acme_credits_grant()` foi mantida como wrapper legado. Isso
 * preserva integrações, shortcodes, admin_post e qualquer código externo que já
 * chame essa função diretamente.
 */
final class CreditGrantService
{
    /** @var CreditRepository */
    private $creditRepository;

    public function __construct(CreditRepository $creditRepository)
    {
        $this->creditRepository = $creditRepository;
    }

    /**
     * Concede créditos e registra auditoria da operação.
     *
     * Risco controlado:
     * A operação mexe em saldo e auditoria, portanto mantemos transação SQL igual
     * ao legado. Qualquer falha durante insert/update resulta em rollback.
     *
     * @param int|string $service Slug público do serviço ou ID numérico legado.
     * @return array{success:bool,message:string,tx_id:int|null}
     */
    public function grant(int $userId, $service, int $creditsAmount, ?string $expiresAt = null, ?string $notes = null, ?array $meta = null): array
    {
        if ($userId <= 0 || $creditsAmount <= 0) {
            return ['success' => false, 'message' => 'Parâmetros inválidos.', 'tx_id' => null];
        }

        $serviceId = 0;

        if (is_numeric($service)) {
            $serviceId = (int) $service;
        } else {
            $serviceSlug = sanitize_text_field((string) $service);

            if ($serviceSlug === '') {
                return ['success' => false, 'message' => 'Serviço inválido.', 'tx_id' => null];
            }

            $serviceRow = $this->creditRepository->findServiceBySlug($serviceSlug);

            if (!$serviceRow) {
                return ['success' => false, 'message' => 'Serviço não encontrado.', 'tx_id' => null];
            }

            $serviceId = (int) $serviceRow->id;
        }

        $actorId = get_current_user_id();
        $now = current_time('mysql');
        $metaJson = wp_json_encode(is_array($meta) ? $meta : []);

        $this->creditRepository->startTransaction();

        $beforeWallet = $this->creditRepository->findWallet($userId, $serviceId);

        if ($this->creditRepository->hasLastError()) {
            $this->creditRepository->rollback();
            return ['success' => false, 'message' => $this->creditRepository->describeLastDatabaseError('Erro ao buscar wallet'), 'tx_id' => null];
        }

        $beforeTotal = (int) ($beforeWallet->credits_total ?? 0);
        $beforeUsed = (int) ($beforeWallet->credits_used ?? 0);

        if (!$beforeWallet) {
            $walletInserted = $this->creditRepository->insertWallet([
                'master_user_id' => $userId,
                'service_id' => $serviceId,
                'credits_total' => $creditsAmount,
                'credits_used' => 0,
                'expires_at' => $expiresAt ?: null,
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            if ($walletInserted === false) {
                $this->creditRepository->rollback();
                return ['success' => false, 'message' => $this->creditRepository->describeLastDatabaseError('Erro ao inserir wallet'), 'tx_id' => null];
            }
        } else {
            $walletData = [
                'credits_total' => $beforeTotal + $creditsAmount,
                'updated_at' => $now,
                'status' => 'active',
            ];

            if ($expiresAt !== null && $expiresAt !== '') {
                $walletData['expires_at'] = $expiresAt;
            }

            $walletUpdated = $this->creditRepository->updateWallet((int) $beforeWallet->id, $walletData);

            if ($walletUpdated === false) {
                $this->creditRepository->rollback();
                return ['success' => false, 'message' => $this->creditRepository->describeLastDatabaseError('Erro ao atualizar wallet'), 'tx_id' => null];
            }
        }

        $afterWallet = $this->creditRepository->findWallet($userId, $serviceId);

        if ($this->creditRepository->hasLastError() || !$afterWallet) {
            $this->creditRepository->rollback();
            return ['success' => false, 'message' => $this->creditRepository->describeLastDatabaseError('Erro ao buscar wallet (after)'), 'tx_id' => null];
        }

        $transactionInserted = $this->creditRepository->insertCreditTransaction([
            'type' => 'grant',
            'actor_user_id' => $actorId,
            'user_id' => $userId,
            'service_id' => $serviceId,
            'credits' => $creditsAmount,
            'attempts' => 1,
            'wallet_total_before' => $beforeTotal,
            'wallet_used_before' => $beforeUsed,
            'wallet_total_after' => (int) $afterWallet->credits_total,
            'wallet_used_after' => (int) $afterWallet->credits_used,
            'status' => 'success',
            'notes' => $notes,
            'meta' => $metaJson,
            'origin' => 'concession',
            'created_at' => $now,
        ]);

        if ($transactionInserted === false) {
            $this->creditRepository->rollback();
            return ['success' => false, 'message' => $this->creditRepository->describeLastDatabaseError('Erro ao inserir transação'), 'tx_id' => null];
        }

        $transactionId = $this->creditRepository->getLastInsertId();
        $this->creditRepository->commit();

        return ['success' => true, 'message' => 'Créditos concedidos.', 'tx_id' => $transactionId];
    }
}
