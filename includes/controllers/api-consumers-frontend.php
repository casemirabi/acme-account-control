<?php

if (!defined('ABSPATH')) {
    exit;
}

add_shortcode('acme_api_control_panel', 'acme_api_control_panel_shortcode');
add_action('wp_enqueue_scripts', 'acme_api_control_panel_enqueue_assets');

function acme_api_control_panel_shortcode()
{
    if (!is_user_logged_in()) {
        return '<div class="acme-api-panel-message">Faça login para acessar este painel.</div>';
    }

    if (!current_user_can('manage_options')) {
        return '<div class="acme-api-panel-message">Você não tem permissão para acessar este painel.</div>';
    }

    acme_api_control_panel_handle_post();

    ob_start();

    $viewFile = ACME_ACC_PATH . 'includes/views/api-consumers-panel.php';

    if (!file_exists($viewFile)) {
        return '<div class="acme-api-panel-message">View do painel não encontrada: ' . esc_html($viewFile) . '</div>';
    }

    include $viewFile;

    return ob_get_clean();
}

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
        array(),
        '1.0.0'
    );
}

function acme_api_control_panel_handle_post()
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        return;
    }

    if (
        !isset($_POST['acme_api_nonce']) ||
        !wp_verify_nonce($_POST['acme_api_nonce'], 'acme_api_panel')
    ) {
        return;
    }

    if (!current_user_can('manage_options')) {
        return;
    }

    if (isset($_POST['api_global_toggle'])) {
        $enabled = sanitize_text_field(wp_unslash($_POST['api_global_toggle'])) === '1';
        acme_api_public_set_enabled($enabled);
    }
}