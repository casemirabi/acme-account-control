<?php

if (!defined('ABSPATH')) {
    exit;
}

if (!shortcode_exists('acme_my_grandchildren')) {
    add_shortcode(
        'acme_my_grandchildren',
        'acme_controller_my_grandchildren'
    );
}

function acme_controller_my_grandchildren()
{
    if (!function_exists('acme_shortcode_my_grandchildren')) {
        return '<p>Erro interno.</p>';
    }

    return acme_shortcode_my_grandchildren();
}
