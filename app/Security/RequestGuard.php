<?php

declare(strict_types=1);

namespace Acme\AccountControl\Security;

/**
 * Guarda de segurança para fluxos HTTP do plugin.
 *
 * Objetivo:
 * Centralizar validações comuns de segurança usadas por Controllers e callbacks
 * administrativos, sem criar um framework próprio ou acoplar regras de negócio
 * aos hooks do WordPress.
 *
 * Por que esta classe existe:
 * O plugin legado possui validações de permissão, nonce e redirecionamento
 * espalhadas em funções diferentes. Concentrar esses pontos reduz duplicação,
 * facilita auditoria de segurança e evita que novos Controllers esqueçam checks
 * obrigatórios antes de executar ações sensíveis.
 *
 * Integração com WordPress:
 * Esta classe usa APIs nativas como `current_user_can()`, `check_admin_referer()`
 * e `wp_safe_redirect()`. Assim preservamos o comportamento esperado do painel e
 * não introduzimos dependências externas.
 *
 * Compatibilidade:
 * Nenhum hook, slug, endpoint, função pública ou tabela é alterado. A classe é
 * uma camada auxiliar para novas migrações MVC e pode ser adotada gradualmente.
 */
final class RequestGuard
{
    /**
     * Valida se o usuário atual possui uma capability específica.
     *
     * Risco mitigado:
     * Bloqueia execução de rotas administrativas por usuários sem permissão.
     * Mantemos a capability como parâmetro para preservar fluxos existentes que
     * possam usar permissões diferentes de `manage_options` no futuro.
     */
    public function requireCapability(string $capability = 'manage_options'): void
    {
        if (!current_user_can($capability)) {
            wp_die('Sem permissão.');
        }
    }

    /**
     * Valida nonce de formulários administrativos.
     *
     * Dependência:
     * Deve ser chamado apenas em contexto WordPress, pois depende de
     * `check_admin_referer()`. O nome do campo permanece configurável para não
     * quebrar formulários legados que usam nomes próprios.
     */
    public function requireAdminNonce(string $action, string $fieldName = '_wpnonce'): void
    {
        check_admin_referer($action, $fieldName);
    }

    /**
     * Sanitiza uma string recebida de request usando a API do WordPress.
     *
     * Comportamento esperado:
     * Retorna string vazia quando o índice não existe, evitando notices em
     * callbacks antigos e mantendo Controllers pequenos.
     *
     * @param array<string,mixed> $source Normalmente `$_POST` ou `$_GET`.
     */
    public function readSanitizedText(array $source, string $key): string
    {
        if (!isset($source[$key])) {
            return '';
        }

        return sanitize_text_field(wp_unslash((string) $source[$key]));
    }

    /**
     * Converte entrada de request para inteiro positivo.
     *
     * Motivo:
     * IDs de usuário, serviço, carteira e transação são fluxos críticos. Usar um
     * método comum evita casts inconsistentes e reduz risco de queries com valor
     * inválido chegando aos repositories.
     *
     * @param array<string,mixed> $source Normalmente `$_POST` ou `$_GET`.
     */
    public function readPositiveInt(array $source, string $key): int
    {
        if (!isset($source[$key])) {
            return 0;
        }

        $value = absint(wp_unslash((string) $source[$key]));

        return $value > 0 ? $value : 0;
    }

    /**
     * Redireciona com segurança para uma página do admin preservando slugs.
     *
     * Impacto:
     * Centraliza `admin_url()` + `wp_safe_redirect()` e evita URLs montadas de
     * forma manual em novos Controllers. O slug continua sendo informado pelo
     * fluxo chamador para manter compatibilidade com bookmarks e permissões.
     *
     * @param array<string,string|int> $queryArgs Argumentos extras de querystring.
     */
    public function redirectToAdminPage(string $pageSlug, array $queryArgs = []): void
    {
        $url = add_query_arg(
            array_merge(['page' => $pageSlug], $queryArgs),
            admin_url('admin.php')
        );

        wp_safe_redirect($url);
        exit;
    }
}
