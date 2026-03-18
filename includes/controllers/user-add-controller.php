<?php

if (!defined('ABSPATH')) {
    exit;
}

if (!shortcode_exists('acme_add_user')) {
    add_shortcode(
        'acme_add_user',
        'acme_controller_add_user'
    );
}

function acme_controller_add_user()
{
    if (!function_exists('acme_shortcode_add_user')) {
        return '<p>Erro interno.</p>';
    }

    return acme_shortcode_add_user();
}
