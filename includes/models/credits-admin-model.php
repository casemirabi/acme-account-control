<?php
if (!defined('ABSPATH')) exit;

if (!function_exists('acme_credits_admin_model_get_page_data')) {
    function acme_credits_admin_model_get_page_data(): array
    {
        global $wpdb;

        $servicesTableName = acme_table_services();

        $serviceItems = $wpdb->get_results(
            "SELECT slug, name, credits_cost FROM {$servicesTableName} ORDER BY name ASC"
        );

        $creditTargetUsers = get_users([
            'role__in' => ['child', 'grandchild'],
            'orderby'  => 'display_name',
            'order'    => 'ASC',
            'number'   => 500,
        ]);

        $masterUsers = get_users([
            'role__in' => ['child'],
            'orderby'  => 'display_name',
            'order'    => 'ASC',
            'number'   => 500,
        ]);

        $statusMessage = isset($_GET['acme_msg'])
            ? sanitize_text_field(wp_unslash($_GET['acme_msg']))
            : '';

        return [
            'serviceItems'      => is_array($serviceItems) ? $serviceItems : [],
            'creditTargetUsers' => is_array($creditTargetUsers) ? $creditTargetUsers : [],
            'masterUsers'       => is_array($masterUsers) ? $masterUsers : [],
            'statusMessage'     => $statusMessage,
        ];
    }
}