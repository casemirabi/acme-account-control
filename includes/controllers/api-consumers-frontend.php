<?php

if (!defined('ABSPATH')) {
    exit;
}

add_shortcode('acme_api_control_panel', 'acme_api_control_panel_shortcode');
add_action('wp_enqueue_scripts', 'acme_api_control_panel_enqueue_assets');

if (!function_exists('acme_api_control_panel_shortcode')) {
    function acme_api_control_panel_shortcode()
    {
        if (!is_user_logged_in()) {
            return '<div class="acme-api-panel-message">Faça login para acessar este painel.</div>';
        }

        /*if (!current_user_can('manage_options')) {
            return '<div class="acme-api-panel-message">Você não tem permissão para acessar este painel.</div>';
            
        }*/

        if (!current_user_can('manage_options')) {
            return '<script>window.location.href="' . esc_url(home_url('/sem-permissao/')) . '";</script>';
        }

        acme_api_control_panel_handle_post();

        $viewFile = ACME_ACC_PATH . 'includes/views/api-consumers-panel.php';
        if (!file_exists($viewFile)) {
            return '<div class="acme-api-panel-message">View do painel não encontrada.</div>';
        }

        ob_start();
        include $viewFile;
        return ob_get_clean();
    }
}

if (!function_exists('acme_api_control_panel_enqueue_assets')) {
    function acme_api_control_panel_enqueue_assets()
    {
        if (!is_user_logged_in() || !current_user_can('manage_options')) {
            return;
        }

        global $post;

        if (!$post instanceof WP_Post) {
            return;
        }

        if (!has_shortcode($post->post_content, 'acme_api_control_panel')) {
            return;
        }

        wp_enqueue_style(
            'acme-api-control-panel',
            ACME_ACC_URL . 'assets/css/acme-api-panel.css',
            [],
            '1.0.1'
        );
    }
}

if (!function_exists('acme_api_control_panel_handle_post')) {
    function acme_api_control_panel_handle_post()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            return;
        }

        if (!isset($_POST['acme_api_panel_action'])) {
            return;
        }

        if (
            !isset($_POST['acme_api_nonce']) ||
            !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['acme_api_nonce'])), 'acme_api_panel')
        ) {
            acme_api_control_panel_store_notice('error', 'Falha de segurança. Recarregue a página e tente novamente.');
            return;
        }

        if (!current_user_can('manage_options')) {
            acme_api_control_panel_store_notice('error', 'Sem permissão para executar esta ação.');
            return;
        }

        $panelAction = sanitize_key(wp_unslash($_POST['acme_api_panel_action']));

        if ($panelAction === 'toggle_global_api') {
            $enabled = isset($_POST['api_global_toggle']) && wp_unslash($_POST['api_global_toggle']) === '1';

            if (!function_exists('acme_api_public_set_enabled')) {
                acme_api_control_panel_store_notice('error', 'Controle global da API não está disponível.');
                return;
            }

            acme_api_public_set_enabled($enabled);

            acme_api_control_panel_store_notice(
                'success',
                $enabled
                    ? 'API pública reativada com sucesso.'
                    : 'API pública bloqueada com sucesso.'
            );

            return;
        }

        if ($panelAction === 'create_consumer_key') {
            $wpUserId = isset($_POST['consumer_user_id']) ? (int) wp_unslash($_POST['consumer_user_id']) : 0;
            $consumerName = isset($_POST['consumer_name'])
                ? sanitize_text_field(wp_unslash($_POST['consumer_name']))
                : '';

            $allowedServices = isset($_POST['allowed_services'])
                ? array_map('sanitize_key', (array) wp_unslash($_POST['allowed_services']))
                : ['clt'];

            if ($wpUserId <= 0) {
                acme_api_control_panel_store_notice('error', 'Selecione um usuário válido.');
                return;
            }

            if ($consumerName === '') {
                acme_api_control_panel_store_notice('error', 'Informe o nome da chave.');
                return;
            }

            if (empty($allowedServices)) {
                $allowedServices = ['clt'];
            }

            $createResult = acme_api_consumer_create($wpUserId, $consumerName, $allowedServices);

            if (is_wp_error($createResult)) {
                acme_api_control_panel_store_notice('error', $createResult->get_error_message());
                return;
            }

            set_transient(
                'acme_api_consumer_plain_key_front_' . get_current_user_id(),
                $createResult,
                300
            );

            acme_api_control_panel_store_notice('success', 'Chave criada com sucesso.');
            return;
        }

        if ($panelAction === 'update_consumer_status') {
            $consumerId = isset($_POST['consumer_id']) ? (int) wp_unslash($_POST['consumer_id']) : 0;
            $targetStatus = isset($_POST['target_status'])
                ? sanitize_key(wp_unslash($_POST['target_status']))
                : '';

            if ($consumerId <= 0) {
                acme_api_control_panel_store_notice('error', 'Consumidor inválido.');
                return;
            }

            if (!in_array($targetStatus, ['active', 'inactive', 'revoked'], true)) {
                acme_api_control_panel_store_notice('error', 'Status de destino inválido.');
                return;
            }

            if ($targetStatus === 'revoked') {
                if (!function_exists('acme_api_consumer_revoke')) {
                    acme_api_control_panel_store_notice('error', 'Função de revogação não disponível.');
                    return;
                }

                $result = acme_api_consumer_revoke($consumerId);

                if ($result !== true) {
                    acme_api_control_panel_store_notice('error', 'Não foi possível revogar a chave.');
                    return;
                }
            } else {
                if (!function_exists('acme_api_consumer_update_status')) {
                    acme_api_control_panel_store_notice('error', 'Função de atualização de status não disponível.');
                    return;
                }

                $result = acme_api_consumer_update_status($consumerId, $targetStatus);

                if (is_wp_error($result)) {
                    acme_api_control_panel_store_notice('error', $result->get_error_message());
                    return;
                }
            }

            if ($targetStatus === 'active') {
                acme_api_control_panel_store_notice('success', 'Chave reativada com sucesso.');
                return;
            }

            if ($targetStatus === 'inactive') {
                acme_api_control_panel_store_notice('success', 'Chave inativada com sucesso.');
                return;
            }

            acme_api_control_panel_store_notice('success', 'Chave revogada com sucesso.');
            return;
        }

        acme_api_control_panel_store_notice('error', 'Ação do painel não reconhecida.');
    }
}

if (!function_exists('acme_api_control_panel_store_notice')) {
    function acme_api_control_panel_store_notice(string $noticeType, string $noticeMessage): void
    {
        set_transient(
            'acme_api_panel_notice_' . get_current_user_id(),
            [
                'type' => $noticeType,
                'message' => $noticeMessage,
            ],
            60
        );
    }
}
