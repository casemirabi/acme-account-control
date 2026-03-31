<?php
if (!defined('ABSPATH')) {
    exit;
}

add_action('user_register', 'acme_users_auto_link_child_to_master_admin');

if (!function_exists('acme_users_auto_link_child_to_master_admin')) {
    function acme_users_auto_link_child_to_master_admin($user_id)
    {
        if (empty($_POST['role']) || $_POST['role'] !== 'child') {
            return;
        }

        global $wpdb;
        $links = acme_table_links();
        $admin_id = acme_master_admin_id();

        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$links} WHERE parent_user_id=%d AND child_user_id=%d AND depth=1",
            $admin_id,
            $user_id
        ));

        if ($exists) {
            return;
        }

        $wpdb->insert($links, [
            'parent_user_id' => $admin_id,
            'child_user_id' => $user_id,
            'depth' => 1,
        ]);
    }
}
