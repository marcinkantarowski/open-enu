<?php

declare(strict_types=1);

namespace App\Module\Role\Handler;

use App\Module\Role\Contract\RoleHoldersInterface;
use App\Module\Role\Command\DeleteRole;
use App\Module\Role\Repository\RoleRepository;
use App\Module\Role\Service\PermissionResolver;
use Doctrine\ORM\EntityManagerInterface;
use OpenEnu\Kernel\Command\SnapshotCollector;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class DeleteRoleHandler
{
    public function __construct(
        private RoleRepository $roles,
        private RoleHoldersInterface $holders,
        private PermissionResolver $permissions,
        private EntityManagerInterface $em,
        private SnapshotCollector $snapshots,
    ) {
    }

    public function __invoke(DeleteRole $command): void
    {
        $role = $this->roles->byKey($command->key);
        if ($role === null || $role->isBuiltIn()) {
            throw new NotFoundHttpException('No such role, or one that cannot be deleted.');
        }

        // Refused rather than cascaded: a membership pointing at no role holds
        // nothing, and its person would lose access with no one having decided it.
        $holders = $this->holders->holding($role->tenantId(), $role->key());
        if ($holders > 0) {
            throw new ConflictHttpException(sprintf('%d member(s) still hold this role. Give them another one first.', $holders));
        }

        $this->snapshots->before($role->toArray());
        $this->em->remove($role);
        $this->em->flush();
        $this->permissions->forget($role->tenantId());
    }
}
