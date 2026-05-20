<?php

declare(strict_types=1);

namespace Acme\AccountControl\Controllers\Admin;

/**
 * Controller administrativo das chaves da API.
 *
 * Esta classe prepara a migração dos callbacks `admin_post` relacionados a
 * consumidores de API. Nesta etapa, ela centraliza comportamentos seguros e
 * pequenos, enquanto os callbacks legados continuam carregados para preservar
 * exatamente os mesmos hooks públicos.
 *
 * Compatibilidade preservada:
 * - Slug `acme-api-consumers` não muda.
 * - Actions `admin_post_acme_api_consumer_*` não mudam.
 * - Transients usados para notices/chave em texto claro não mudam.
 */
final class ApiConsumersAdminController extends AdminPageController
{
    /**
     * Redireciona o menu raiz de fallback para a tela de chaves da API.
     */
    public function redirectFallbackPage(): void
    {
        $this->assertManageOptions();
        $this->redirectToAdminPage('acme-api-consumers');
    }

    /**
     * Armazena uma notice administrativa temporária.
     *
     * Motivo:
     * Mantém o mesmo transient legado para que a View antiga continue lendo as
     * mensagens sem qualquer alteração visual ou de comportamento.
     */
    public function storeNotice(string $noticeType, string $noticeMessage): void
    {
        set_transient('acme_api_consumer_notice_' . get_current_user_id(), [
            'type' => $noticeType,
            'message' => $noticeMessage,
        ], 60);
    }
}
