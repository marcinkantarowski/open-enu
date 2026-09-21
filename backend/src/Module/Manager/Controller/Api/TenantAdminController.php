<?php

declare(strict_types=1);

namespace App\Module\Manager\Controller\Api;

use App\Module\Identity\Contract\UserDirectoryInterface;
use App\Module\Manager\Command\SuspendTenant;
use App\Module\Tenant\Contract\TenantReaderInterface;
use OpenEnu\Kernel\Command\CommandBusInterface;
use OpenEnu\Kernel\Doctrine\ScopeContext;
use OpenEnu\Kernel\Dto\ListResponse;
use OpenEnu\Kernel\Dto\PaginationRequest;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The operator's view across every tenant.
 *
 * This is the one surface that deliberately reads across tenants, and it does so
 * explicitly: `runUnscoped` with a reason, on a firewall that only operator
 * tokens can reach. The filter is not bypassed by accident anywhere - here it is
 * bypassed on purpose, and the reason is recorded.
 */
final readonly class TenantAdminController
{
    public function __construct(
        private TenantReaderInterface $reader,
        private UserDirectoryInterface $directory,
        private CommandBusInterface $commands,
        private ScopeContext $scope,
    ) {
    }

    #[Route('/api/manager/tenants', name: 'manager_tenant_index', methods: ['GET'])]
    #[IsGranted('ROLE_PLATFORM_MANAGER')]
    public function index(Request $request): JsonResponse
    {
        $pagination = PaginationRequest::fromRequest($request, ['name', 'createdAt']);

        return $this->scope->runUnscoped(
            'the operator console lists every tenant by design',
            function () use ($pagination): JsonResponse {
                $items = array_map(
                    fn (array $tenant): array => [
                        ...$tenant,
                        'members' => $this->directory->countMembersOf((string) $tenant['id']),
                    ],
                    $this->reader->page($pagination->offset(), $pagination->size),
                );

                return new JsonResponse(
                    ListResponse::of($items, $this->reader->total(), $pagination)->jsonSerialize(),
                );
            },
        );
    }

    #[Route('/api/manager/tenants/{id}', name: 'manager_tenant_show', methods: ['GET'])]
    #[IsGranted('ROLE_PLATFORM_MANAGER')]
    public function show(string $id): JsonResponse
    {
        $tenant = $this->reader->describe($id) ?? throw new NotFoundHttpException('No such tenant.');

        return new JsonResponse([
            ...$tenant,
            'members' => $this->directory->membersOf($id),
        ]);
    }

    #[Route('/api/manager/tenants/{id}/suspend', name: 'manager_tenant_suspend', methods: ['POST'])]
    #[IsGranted('ROLE_PLATFORM_MANAGER')]
    public function suspend(string $id): JsonResponse
    {
        $this->commands->dispatch(new SuspendTenant($id));

        return new JsonResponse(['status' => 'suspended']);
    }
}
