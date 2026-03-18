<?php
if (!defined('ABSPATH')) exit;

if (!shortcode_exists('acme_edit_user')) {
    add_shortcode('acme_edit_user','acme_controller_edit_user');
}

function acme_controller_edit_user(){
    return function_exists('acme_shortcode_edit_user')
        ? acme_shortcode_edit_user()
        : '<p>Erro</p>';
}
