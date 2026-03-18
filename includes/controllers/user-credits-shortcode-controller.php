<?php

if (!defined('ABSPATH')) {
    exit;
}

if (!shortcode_exists('acme_edit_user_credits_page')) {
    add_shortcode(
        'acme_edit_user_credits_page',
        'acme_controller_edit_user_credits_page'
    );
}

function acme_controller_edit_user_credits_page()
{
    if (!function_exists('acme_shortcode_edit_user_credits_page')) {
        return '<p>Erro interno (credits shortcode).</p>';
    }

    return acme_shortcode_edit_user_credits_page();
}
