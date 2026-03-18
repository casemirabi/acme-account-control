<?php

if (!defined('ABSPATH')) {
    exit;
}

if (!shortcode_exists('acme_view_user')) {
    add_shortcode(
        'acme_view_user',
        'acme_controller_view_user'
    );
}

function acme_controller_view_user()
{
    if (!function_exists('acme_shortcode_view_user')) {
        return '<p>Erro interno.</p>';
    }

    return acme_shortcode_view_user();
}
