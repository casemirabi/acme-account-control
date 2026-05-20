<?php

declare(strict_types=1);

namespace Acme\AccountControl\Controllers\Frontend;

use Acme\AccountControl\Security\RequestGuard;
use Acme\AccountControl\Views\View;

/**
 * Controller do shortcode `[acme_api_control_panel]`.
 *
 * Responsabilidade:
 * Receber a chamada do shortcode, validar acesso simples, acionar o fluxo de
 * POST legado quando necessário e renderizar a View do painel.
 *
 * Por que este Controller existe:
 * Antes, o shortcode misturava validação, enqueue de assets, processamento de
 * formulário e include de template na mesma função global. Esta classe separa a
 * entrada HTTP da renderização, sem alterar o shortcode público nem a View atual.
 *
 * Compatibilidade:
 * - O shortcode continua sendo `[acme_api_control_panel]`.
 * - O template foi movido para `app/Views/frontend/api-consumers-panel.php`.
 * - A renderização agora usa o renderer MVC, sem manter template em `includes/views`.
 * - A função global `acme_api_control_panel_shortcode()` permanece como wrapper
 *   para instalações ou snippets que a chamem diretamente.
 */
final class ApiControlPanelController
{
    /** @var string */
    private $pluginPath;

    /** @var View */
    private $view;

    /** @var RequestGuard */
    private $requestGuard;

    public function __construct(string $pluginPath, ?RequestGuard $requestGuard = null)
    {
        $this->pluginPath = rtrim($pluginPath, '/\\') . DIRECTORY_SEPARATOR;
        $this->view = new View($this->pluginPath);
        // Guarda reutilizável para futuras validações de shortcode/formulário.
        // Nesta etapa ele não altera permissões públicas; apenas centraliza a
        // dependência para migrações seguras posteriores.
        $this->requestGuard = $requestGuard ?: new RequestGuard();
    }

    /**
     * Renderiza o shortcode do painel da API.
     *
     * Fluxo esperado:
     * 1. Bloquear visitantes deslogados.
     * 2. Bloquear usuários sem permissão administrativa.
     * 3. Carregar assets necessários.
     * 4. Processar POST usando a rotina legada preservada.
     * 5. Renderizar a View legada via renderer centralizado.
     */
    public function shortcode(): string
    {
        if (!is_user_logged_in()) {
            return '<div class="acme-api-panel-message">Faça login para acessar este painel.</div>';
        }

        if (!current_user_can('manage_options')) {
            return '<script>window.location.href="' . esc_url(home_url('/sem-permissao/')) . '";</script>';
        }

        $this->enqueuePanelAssets('1.0.3');

        // Mantém o processamento legado de POST nesta etapa para reduzir risco.
        // A próxima migração pode mover esse fluxo para um Service dedicado.
        if (function_exists('acme_api_control_panel_handle_post')) {
            acme_api_control_panel_handle_post();
        }

        try {
            return $this->view->capture('frontend.api-consumers-panel');
        } catch (\Throwable $exception) {
            // Shortcodes não devem gerar erro fatal na página pública. Retornamos
            // mensagem segura e registramos o detalhe para debug do servidor.
            error_log('[ACME] Falha ao renderizar painel da API: ' . $exception->getMessage());
            return '<div class="acme-api-panel-message">View do painel não encontrada.</div>';
        }
    }

    /**
     * Carrega assets do painel apenas quando o shortcode está presente.
     *
     * Esse método preserva o comportamento antigo de verificar o conteúdo do post
     * atual antes de enfileirar CSS/JS, evitando carregar assets em páginas que
     * não usam o painel.
     */
    public function enqueueAssetsWhenShortcodeIsPresent(): void
    {
        if (!is_user_logged_in() || !current_user_can('manage_options')) {
            return;
        }

        global $post;

        if (!$post instanceof \WP_Post) {
            return;
        }

        if (!has_shortcode($post->post_content, 'acme_api_control_panel')) {
            return;
        }

        $this->enqueuePanelAssets('1.0.2');
    }

    /**
     * Enfileira CSS e JS usados pelo painel da API.
     *
     * Dependência:
     * Usa a constante `ACME_ACC_URL`, já definida pela camada de configuração do
     * plugin. A constante precisa existir antes do carregamento dos hooks.
     */
    private function enqueuePanelAssets(string $layoutScriptVersion): void
    {
        wp_enqueue_style(
            'acme-api-control-panel',
            ACME_ACC_URL . 'assets/css/acme-api-panel.css',
            [],
            '1.0.1'
        );

        wp_enqueue_script(
            'acme-shortcode-layout',
            ACME_ACC_URL . 'assets/js/acme-shortcode-layout.js',
            [],
            $layoutScriptVersion,
            true
        );
    }
}
