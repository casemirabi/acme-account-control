<?php

declare(strict_types=1);

namespace Acme\AccountControl\Controllers\Rest;

use Acme\AccountControl\Models\ApiAccessLogRepository;
use Acme\AccountControl\Models\ServiceRequestRepository;
use Acme\AccountControl\Services\ApiResourceLinkService;
use Acme\AccountControl\Services\MasterAccessService;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Controller REST para recursos INSS.
 *
 * Responsabilidades:
 * - expor recursos por URLs opacas;
 * - validar autenticação por API key/sessão;
 * - aplicar regra de Login Master;
 * - registrar logs de acesso.
 */
final class InssResourceController
{
    /** @var ServiceRequestRepository */
    private $serviceRequestRepository;

    /** @var MasterAccessService */
    private $masterAccessService;

    /** @var ApiResourceLinkService */
    private $resourceLinkService;

    /** @var ApiAccessLogRepository */
    private $accessLogRepository;

    public function __construct()
    {
        $this->serviceRequestRepository = new ServiceRequestRepository();
        $this->masterAccessService = new MasterAccessService();
        $this->resourceLinkService = new ApiResourceLinkService($this->serviceRequestRepository);
        $this->accessLogRepository = new ApiAccessLogRepository();
    }

    /**
     * Registra rotas novas sem remover endpoints legados.
     */
    public function registerRoutes(): void
    {
        register_rest_route('acme/v1', '/inss/resources/pdf/(?P<pdf_public_id>[a-zA-Z0-9_\-]+)', [
            'methods' => 'GET',
            'callback' => [$this, 'downloadPdf'],
            'permission_callback' => [$this, 'authorizeInssRequest'],
        ]);

        register_rest_route('acme/v1', '/inss/resources', [
            'methods' => 'GET',
            'callback' => [$this, 'listResources'],
            'permission_callback' => [$this, 'authorizeInssRequest'],
        ]);
    }

    /**
     * Autentica a chamada e injeta contexto reutilizável no request.
     */
    public function authorizeInssRequest(WP_REST_Request $request)
    {
        $authContext = function_exists('acme_resolve_authenticated_user_context')
            ? acme_resolve_authenticated_user_context($request)
            : null;

        if (is_wp_error($authContext)) {
            return $authContext;
        }

        if (!is_array($authContext)) {
            return new WP_Error('acme_auth_unavailable', 'Autenticação indisponível.', ['status' => 401]);
        }

        $actorUserId = (int) ($authContext['user_id'] ?? 0);

        if ($actorUserId <= 0) {
            return new WP_Error('acme_invalid_actor', 'Usuário autenticado inválido.', ['status' => 401]);
        }

        $request->set_param('acme_actor_user_id', $actorUserId);
        $request->set_param('acme_auth_source', (string) ($authContext['source'] ?? 'api'));

        return true;
    }

    /**
     * Lista recursos INSS acessíveis pelo usuário autenticado ou master.
     */
    public function listResources(WP_REST_Request $request): WP_REST_Response
    {
        $actorUserId = (int) $request->get_param('acme_actor_user_id');
        $limit = (int) ($request->get_param('limit') ?: 50);
        $offset = (int) ($request->get_param('offset') ?: 0);
        $visibleUserIds = $this->masterAccessService->getVisibleUserIds($actorUserId);
        $rows = $this->serviceRequestRepository->listInssByUserIds($visibleUserIds, $limit, $offset);

        $items = [];

        foreach ($rows as $row) {
            $items[] = $this->formatResourceItem($row);
        }

        $this->accessLogRepository->record([
            'actor_user_id' => $actorUserId,
            'target_user_id' => $actorUserId,
            'service_slug' => 'inss',
            'provider' => 'rest',
            'action' => 'inss.resources.list',
            'status' => 'success',
            'message' => 'Listagem de recursos INSS via API.',
            'payload' => ['limit' => $limit, 'offset' => $offset, 'visible_user_ids' => $visibleUserIds],
        ]);

        return new WP_REST_Response([
            'success' => true,
            'data' => [
                'items' => $items,
                'limit' => max(1, min(200, $limit)),
                'offset' => max(0, $offset),
            ],
        ], 200);
    }

    /**
     * Entrega o PDF sem token/query string e sem expor request_id na URL.
     */
    public function downloadPdf(WP_REST_Request $request)
    {
        $actorUserId = (int) $request->get_param('acme_actor_user_id');
        $pdfPublicId = sanitize_text_field((string) $request->get_param('pdf_public_id'));
        $row = $this->serviceRequestRepository->findInssByPdfPublicId($pdfPublicId);

        if (!$row) {
            $this->logPdfAccess($actorUserId, 0, '', $pdfPublicId, 'not_found', 'Recurso PDF INSS não encontrado.');
            return new WP_Error('acme_inss_pdf_not_found', 'Recurso PDF INSS não encontrado.', ['status' => 404]);
        }

        $targetUserId = (int) ($row['user_id'] ?? 0);
        $requestId = (string) ($row['request_id'] ?? '');

        if (!$this->masterAccessService->canAccessUser($actorUserId, $targetUserId)) {
            $this->logPdfAccess($actorUserId, $targetUserId, $requestId, $pdfPublicId, 'forbidden', 'Acesso negado ao PDF INSS.');
            return new WP_Error('acme_inss_pdf_forbidden', 'Você não tem permissão para acessar este PDF.', ['status' => 403]);
        }

        if ((string) ($row['status'] ?? '') !== 'completed') {
            $this->logPdfAccess($actorUserId, $targetUserId, $requestId, $pdfPublicId, 'pending', 'Consulta INSS ainda não concluída.');
            return new WP_Error('acme_inss_pdf_not_ready', 'Consulta INSS ainda não foi concluída.', ['status' => 409]);
        }

        $payload = json_decode((string) ($row['response_json'] ?? '{}'), true);

        if (!is_array($payload) || empty($payload['dados'])) {
            $this->logPdfAccess($actorUserId, $targetUserId, $requestId, $pdfPublicId, 'invalid_payload', 'Payload INSS inválido para PDF.');
            return new WP_Error('acme_inss_pdf_invalid_payload', 'Dados da consulta INSS não encontrados.', ['status' => 422]);
        }

        $this->logPdfAccess($actorUserId, $targetUserId, $requestId, $pdfPublicId, 'success', 'PDF INSS entregue via API.');

        $this->streamInssPdf($row, $payload);
        exit;
    }

    /**
     * Formata item da listagem sem expor request_id como URL de recurso.
     */
    private function formatResourceItem(array $row): array
    {
        return [
            'request_id' => (string) ($row['request_id'] ?? ''),
            'owner_user_id' => (int) ($row['user_id'] ?? 0),
            'beneficio' => (string) ($row['cpf_masked'] ?? ''),
            'status' => (string) ($row['status'] ?? 'pending'),
            'created_at' => $row['created_at'] ?? null,
            'updated_at' => $row['updated_at'] ?? null,
            'completed_at' => $row['completed_at'] ?? null,
            'resources' => [
                'pdf' => $this->resourceLinkService->buildInssPdfUrl($row),
            ],
        ];
    }

    /**
     * Centraliza o log de PDF para reduzir duplicação no controller.
     */
    private function logPdfAccess(int $actorUserId, int $targetUserId, string $requestId, string $resourceId, string $status, string $message): void
    {
        $this->accessLogRepository->record([
            'actor_user_id' => $actorUserId,
            'target_user_id' => $targetUserId,
            'service_slug' => 'inss',
            'provider' => 'rest',
            'action' => 'inss.pdf.download',
            'status' => $status,
            'request_id' => $requestId,
            'resource_id' => $resourceId,
            'message' => $message,
        ]);
    }

    /**
     * Usa o serviço TCPDF existente, mantendo fallback HTML legado.
     */
    private function streamInssPdf(array $row, array $payload): void
    {
        $safeFileId = (string) ($row['pdf_public_id'] ?? 'inss');
        $fileName = 'consulta-inss-' . sanitize_file_name($safeFileId) . '.pdf';

        try {
            require_once ACME_PLUGIN_DIR . 'app/Services/InssTcpdfService.php';

            $pdfService = new \InssTcpdfService();
            $pdfService->outputPdf($row, $payload, $fileName);
        } catch (\Throwable $exception) {
            error_log('[ACME][INSS][PDF][REST] ' . $exception->getMessage());

            if (function_exists('acme_inss_build_pdf_html') && function_exists('acme_pdf_stream_html')) {
                $html = acme_inss_build_pdf_html($row, $payload);
                acme_pdf_stream_html($html, $fileName);
            }

            wp_die('Não foi possível gerar o PDF INSS.', 500);
        }
    }
}
