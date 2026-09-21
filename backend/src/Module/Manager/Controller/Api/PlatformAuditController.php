<?php

declare(strict_types=1);

namespace App\Module\Manager\Controller\Api;

use App\Module\Audit\Contract\AuditReaderInterface;
use OpenEnu\Kernel\Doctrine\ScopeContext;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The audit trail, across every tenant.
 *
 * Distinct from the tenant-facing viewer, which narrows to the caller's own
 * tenant. This is the one an incident is investigated from, and it is why
 * `AuditEntry` is deliberately not tenant-scoped: the filter would hide exactly
 * the cross-tenant entries that matter here.
 */
final readonly class PlatformAuditController
{
    public function __construct(
        private AuditReaderInterface $audit,
        private ScopeContext $scope,
    ) {
    }

    #[Route('/api/manager/audit', name: 'manager_audit_index', methods: ['GET'])]
    #[IsGranted('ROLE_PLATFORM_MANAGER')]
    public function index(Request $request): JsonResponse
    {
        $tenantId = $request->query->getString('tenantId') ?: null;

        return $this->scope->runUnscoped(
            'an operator reads the audit trail across tenants by design',
            fn (): JsonResponse => new JsonResponse([
                'items' => $this->audit->recent($tenantId, $request->query->getInt('limit', 100)),
            ]),
        );
    }
}
