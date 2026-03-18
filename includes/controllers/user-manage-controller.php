<?php

if (!defined('ABSPATH')) {
    exit;
}

if (!shortcode_exists('acme_my_grandchildren_manage')) {
    add_shortcode(
        'acme_my_grandchildren_manage',
        'acme_controller_my_grandchildren_manage'
    );
}

function acme_controller_my_grandchildren_manage($atts = [], $content = null, $tag = '')
{
    if (!function_exists('acme_shortcode_my_grandchildren_manage')) {
        return '<p>Erro interno (manage).</p>';
    }

    return acme_shortcode_my_grandchildren_manage($atts);
}