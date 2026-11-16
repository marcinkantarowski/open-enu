<?php

declare(strict_types=1);

namespace App\Module\Role\Handler;

use App\Module\Role\Command\UpdateRole;
use App\Module\Role\Repository\RoleRepository;
use App\Module\Role\Service\PermissionResolver;
use Doctrine\ORM\EntityManagerInterface;
use OpenEnu\Kernel\Command\SnapshotCollector;
use OpenEnu\Kernel\Doctrine\ScopeContext;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class UpdateRoleHandler
{
    public function __construct(
        private RoleRepository $roles,
        private PermissionResolver $permissions,
        private EntityManagerInterface $em,
        private ScopeContext $scope,
        private SnapshotCollector $snapshots,
    ) {
    }

    /** @return array<string, mixed> */
    public function __invoke(UpdateRole $command): array
    {
        $tenantId = $this->scope->tenantId()
            ?? throw new BadRequestHttpException('A role belongs to a tenant; none is in scope.');

        $role = $this->roles->editable($command->key, $tenantId)
            ?? throw new NotFoundHttpException('No such role, or one that cannot be edited.');

        if ($command->name === null && !$role->isBuiltIn()) {
            throw new BadRequestHttpException('A role of your own needs a name.');
        }

        $this->permissions->assertAssignable($command->permissions);

        $this->snapshots->before([...$role->toArray(), 'permissions' => $this->permissions->forRole($tenantId, $role->key())]);
        $role->define($command->name, $command->permissions, $this->permissions->declared());

        $this->em->persist($role);
        $this->em->flush();
        $this->permissions->forget($tenantId);

        $after = $role->toArray();
        $this->snapshots->after($after);

        return $after;
    }
}
