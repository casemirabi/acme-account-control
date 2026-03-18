<?php
if (!defined('ABSPATH')) exit;

if (!shortcode_exists('acme_view_user_atual')) {
    add_shortcode('acme_view_user_atual','acme_controller_view_user_atual');
}

function acme_controller_view_user_atual(){
    return function_exists('acme_shortcode_view_user_atual')
        ? acme_shortcode_view_user_atual()
        : '<p>Erro</p>';
}
