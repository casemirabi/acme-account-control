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
    credit_cost INT NOT NULL DEFAULT 1,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_slug (slug)
  ) {$charset};";

  dbDelta($sql);
}
