<?php

declare(strict_types=1);

namespace App\Module\Audit\Controller\Api;

use App\Module\Audit\Repository\AuditEntryRepository;
use OpenEnu\Kernel\Doctrine\ScopeContext;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final readonly class AuditController
{
    public function __construct(
        private AuditEntryRepository $entries,
        private ScopeContext $scope,
    ) {
    }

    #[Route('/api/audit', name: 'audit_index', methods: ['GET'])]
    #[IsGranted('audit.view')]
    public function index(): JsonResponse
    {
        // Narrowed to the caller's tenant HERE rather than by the query filter:
        // the entity is deliberately unscoped so an operator can investigate
        // across tenants, which makes this the place the restriction belongs.
        return new JsonResponse([
            'items' => array_map(
                static fn (object $e): array => $e->toArray(),
                $this->entries->recent($this->scope->tenantId()),
            ),
        ]);
    }
}
