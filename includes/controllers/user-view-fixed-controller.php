<?php
if (!defined('ABSPATH')) exit;

if (!shortcode_exists('acme_view_user_fixed')) {
    add_shortcode('acme_view_user_fixed','acme_controller_view_user_fixed');
}

function acme_controller_view_user_fixed(){
    return function_exists('acme_shortcode_view_user_fixed')
        ? acme_shortcode_view_user_fixed()
        : '<p>Erro</p>';
}
