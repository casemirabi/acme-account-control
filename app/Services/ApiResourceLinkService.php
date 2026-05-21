<?php

declare(strict_types=1);

namespace Acme\AccountControl\Services;

use Acme\AccountControl\Models\ServiceRequestRepository;

/**
 * Service dedicado à montagem de links de recursos expostos pela API.
 */
final class ApiResourceLinkService
{
    /** @var ServiceRequestRepository */
    private $serviceRequestRepository;

    public function __construct(ServiceRequestRepository $serviceRequestRepository)
    {
        $this->serviceRequestRepository = $serviceRequestRepository;
    }

    /**
     * Retorna a URL pública opaca do PDF, sem expor request_id nem token na URL.
     */
    public function buildInssPdfUrl(array $requestRow): ?string
    {
        $pdfPublicId = $this->serviceRequestRepository->ensurePdfPublicId($requestRow);

        if ($pdfPublicId === '') {
            return null;
        }

        return rest_url('acme/v1/inss/resources/pdf/' . rawurlencode($pdfPublicId));
    }
}
