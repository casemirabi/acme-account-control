<?php

declare(strict_types=1);

namespace Acme\AccountControl\Models;

use wpdb;

/**
 * Model/Repository responsável pela leitura e atualização de service_requests.
 *
 * Mantém as queries fora dos controllers REST, facilitando manutenção e testes.
 */
final class ServiceRequestRepository
{
    /** @var wpdb */
    private $wpdb;

    /** @var string */
    private $tableName;

    public function __construct(?wpdb $wpdb = null)
    {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->tableName = $this->wpdb->prefix . 'service_requests';
    }

    /**
     * Cria colunas usadas pelos recursos públicos da API sem alterar contratos antigos.
     */
    public static function activate(): void
    {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $tableName = $wpdb->prefix . 'service_requests';
        $charsetCollate = $wpdb->get_charset_collate();

        dbDelta("CREATE TABLE {$tableName} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            pdf_public_id VARCHAR(80) NULL DEFAULT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY pdf_public_id (pdf_public_id)
        ) {$charsetCollate};");
    }

    /**
     * Busca uma consulta INSS pelo request_id interno.
     */
    public function findInssByRequestId(string $requestId): ?array
    {
        $requestId = sanitize_text_field($requestId);

        if ($requestId === '') {
            return null;
        }

        $row = $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->tableName}
                 WHERE request_id = %s
                   AND service_slug = %s
                 LIMIT 1",
                $requestId,
                'inss'
            ),
            ARRAY_A
        );

        return is_array($row) ? $row : null;
    }

    /**
     * Busca uma consulta INSS pelo identificador público opaco do PDF.
     */
    public function findInssByPdfPublicId(string $pdfPublicId): ?array
    {
        $pdfPublicId = sanitize_text_field($pdfPublicId);

        if ($pdfPublicId === '') {
            return null;
        }

        $row = $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->tableName}
                 WHERE pdf_public_id = %s
                   AND service_slug = %s
                 LIMIT 1",
                $pdfPublicId,
                'inss'
            ),
            ARRAY_A
        );

        return is_array($row) ? $row : null;
    }

    /**
     * Garante que a consulta possua um identificador público opaco para o recurso PDF.
     */
    public function ensurePdfPublicId(array $requestRow): string
    {
        $currentPublicId = (string) ($requestRow['pdf_public_id'] ?? '');

        if ($currentPublicId !== '') {
            return $currentPublicId;
        }

        $requestId = (string) ($requestRow['request_id'] ?? '');

        if ($requestId === '') {
            return '';
        }

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $candidatePublicId = 'pdf_' . bin2hex(random_bytes(18));

            $updated = $this->wpdb->update(
                $this->tableName,
                [
                    'pdf_public_id' => $candidatePublicId,
                    'updated_at' => current_time('mysql'),
                ],
                [
                    'request_id' => $requestId,
                    'service_slug' => 'inss',
                ],
                ['%s', '%s'],
                ['%s', '%s']
            );

            if ($updated !== false) {
                return $candidatePublicId;
            }
        }

        return '';
    }

    /**
     * Lista consultas INSS pertencentes ao escopo de usuários informado.
     */
    public function listInssByUserIds(array $visibleUserIds, int $limit = 50, int $offset = 0): array
    {
        $visibleUserIds = array_values(array_unique(array_filter(array_map('intval', $visibleUserIds))));

        if (empty($visibleUserIds)) {
            return [];
        }

        $limit = max(1, min(200, $limit));
        $offset = max(0, $offset);
        $placeholders = implode(',', array_fill(0, count($visibleUserIds), '%d'));

        $sql = "SELECT request_id, pdf_public_id, user_id, service_slug, cpf_masked, status,
                       created_at, updated_at, completed_at, error_code, error_message
                FROM {$this->tableName}
                WHERE service_slug = %s
                  AND user_id IN ({$placeholders})
                ORDER BY id DESC
                LIMIT %d OFFSET %d";

        $params = array_merge(['inss'], $visibleUserIds, [$limit, $offset]);
        $rows = $this->wpdb->get_results($this->wpdb->prepare($sql, $params), ARRAY_A);

        return is_array($rows) ? $rows : [];
    }
}
