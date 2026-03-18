<?php
if (!defined('ABSPATH')) exit;

/**
 * ============================================================
 * MÓDULO: Serviços / Créditos (parametrização - Admin)
 * ============================================================
 */

function acme_services_activate(){
  global $wpdb;
  $table = acme_table_services();
  $charset = $wpdb->get_charset_collate();

  require_once ABSPATH.'wp-admin/includes/upgrade.php';

  $sql = "CREATE TABLE {$table} (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    slug VARCHAR(50) NOT NULL,
    name VARCHAR(120) NOT NULL,
    credits_cost INT NOT NULL DEFAULT 1,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_slug (slug)
  ) {$charset};";

  dbDelta($sql);
}

function acme_get_service_credit_cost($slug)
{
    global $wpdb;

    $table = $wpdb->prefix . 'services';

    $cost = $wpdb->get_var(
        $wpdb->prepare(
            "SELECT credits_cost 
             FROM $table 
             WHERE slug = %s 
             AND is_active = 1 
             LIMIT 1",
            $slug
        )
    );

    if ($cost === null) {
        return 0;
    }

    return (int) $cost;
}