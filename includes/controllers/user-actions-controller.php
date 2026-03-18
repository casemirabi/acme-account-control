<?php
if (!defined('ABSPATH')) exit;

add_action('admin_post_acme_fe_toggle_status','acme_controller_toggle_status');
add_action('admin_post_acme_fe_bulk_activate','acme_controller_bulk_activate');
add_action('admin_post_acme_fe_bulk_deactivate','acme_controller_bulk_deactivate');
add_action('admin_post_acme_fe_set_password','acme_controller_set_password');
add_action('admin_post_acme_fe_update_phone','acme_controller_update_phone');
add_action('admin_post_acme_fe_create_user','acme_controller_create_user');

function acme_controller_toggle_status(){ acme_fe_toggle_status(); }
function acme_controller_bulk_activate(){ acme_fe_bulk_activate(); }
function acme_controller_bulk_deactivate(){ acme_fe_bulk_deactivate(); }
function acme_controller_set_password(){ acme_fe_set_password(); }
function acme_controller_update_phone(){ acme_fe_update_phone(); }
function acme_controller_create_user(){ acme_fe_create_user(); }