<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Users module activation: hierarchy links + account status tables.
 */
if (!function_exists('acme_users_activate')) {
    function acme_users_activate()
    {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();

        $linksT = acme_table_links();
        $statusT = acme_table_status();

        $sql_links = "CREATE TABLE {$linksT} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            parent_user_id BIGINT UNSIGNED NOT NULL,
            child_user_id BIGINT UNSIGNED NOT NULL,
            depth TINYINT UNSIGNED NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_link (parent_user_id, child_user_id),
            KEY idx_parent (parent_user_id),
            KEY idx_child (child_user_id),
            KEY idx_depth (depth)
        ) {$charset};";

        $sql_status = "CREATE TABLE {$statusT} (
            user_id BIGINT UNSIGNED NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'active',
            disabled_at DATETIME NULL,
            disabled_by BIGINT UNSIGNED NULL,
            reason VARCHAR(255) NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (user_id),
            KEY idx_status (status),
            KEY idx_disabled_by (disabled_by)
        ) {$charset};";

        dbDelta($sql_links);
        dbDelta($sql_status);
    }
}
