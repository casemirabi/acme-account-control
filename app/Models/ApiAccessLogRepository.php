<?php

declare(strict_types=1);

namespace Acme\AccountControl\Models;

use wpdb;

/**
 * Model responsável por logs de acesso à API e recursos sensíveis.
 */
final class ApiAccessLogRepository
{
    /** @var wpdb */
    private $wpdb;

    /** @var string */
    private $tableName;

    public function __construct(?wpdb $wpdb = null)
    {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->tableName = $this->wpdb->prefix . 'api_logs';
    }

    /**
     * Cria/atualiza a tabela de logs mantendo compatibilidade com a tabela legada.
     */
    public static function activate(): void
    {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $tableName = $wpdb->prefix . 'api_logs';
        $charsetCollate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$tableName} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NULL,
            actor_user_id BIGINT UNSIGNED NULL,
            target_user_id BIGINT UNSIGNED NULL,
            service_slug VARCHAR(100) NULL,
            provider VARCHAR(50) NULL,
            action VARCHAR(100) NULL,
            status VARCHAR(20) NULL,
            request_id VARCHAR(80) NULL,
            resource_id VARCHAR(100) NULL,
            message TEXT NULL,
            payload LONGTEXT NULL,
            ip_address VARCHAR(100) NULL,
            user_agent TEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_user (user_id),
            KEY idx_actor (actor_user_id),
            KEY idx_target (target_user_id),
            KEY idx_service (service_slug),
            KEY idx_request (request_id),
            KEY idx_resource (resource_id),
            KEY idx_created (created_at)
        ) {$charsetCollate};";

        dbDelta($sql);
    }

    /**
     * Registra tentativa de acesso, autorizada ou negada.
     */
    public function record(array $logData): void
    {
        $this->wpdb->insert(
            $this->tableName,
            [
                'user_id' => isset($logData['actor_user_id']) ? (int) $logData['actor_user_id'] : null,
                'actor_user_id' => isset($logData['actor_user_id']) ? (int) $logData['actor_user_id'] : null,
                'target_user_id' => isset($logData['target_user_id']) ? (int) $logData['target_user_id'] : null,
                'service_slug' => sanitize_key((string) ($logData['service_slug'] ?? '')),
                'provider' => sanitize_text_field((string) ($logData['provider'] ?? 'api')),
                'action' => sanitize_text_field((string) ($logData['action'] ?? '')),
                'status' => sanitize_text_field((string) ($logData['status'] ?? '')),
                'request_id' => sanitize_text_field((string) ($logData['request_id'] ?? '')),
                'resource_id' => sanitize_text_field((string) ($logData['resource_id'] ?? '')),
                'message' => sanitize_textarea_field((string) ($logData['message'] ?? '')),
                'payload' => wp_json_encode($logData['payload'] ?? [], JSON_UNESCAPED_UNICODE),
                'ip_address' => isset($_SERVER['REMOTE_ADDR']) ? substr((string) $_SERVER['REMOTE_ADDR'], 0, 100) : null,
                'user_agent' => isset($_SERVER['HTTP_USER_AGENT']) ? substr((string) $_SERVER['HTTP_USER_AGENT'], 0, 1000) : null,
                'created_at' => current_time('mysql'),
            ],
            ['%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s']
        );
    }
}
