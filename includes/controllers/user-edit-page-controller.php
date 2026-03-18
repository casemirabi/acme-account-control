<?php

if (!defined('ABSPATH')) {
    exit;
}

if (!shortcode_exists('acme_edit_user_page')) {
    add_shortcode(
        'acme_edit_user_page',
        'acme_controller_edit_user_page'
    );
}

function acme_controller_edit_user_page()
{
    if (!function_exists('acme_shortcode_edit_user_page')) {
        return '<p>Erro interno (edit user).</p>';
    }

    return acme_shortcode_edit_user_page();
}
