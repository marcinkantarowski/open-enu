<?php

declare(strict_types=1);

namespace App\Module\Role\Handler;

use App\Module\Role\Contract\PermissionsInterface;
use App\Module\Role\Command\CreateRole;
use App\Module\Role\Entity\Role;
use App\Module\Role\Repository\RoleRepository;
use App\Module\Role\Service\PermissionResolver;
use Doctrine\ORM\EntityManagerInterface;
use OpenEnu\Kernel\Command\SnapshotCollector;
use OpenEnu\Kernel\Doctrine\ScopeContext;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\String\Slugger\SluggerInterface;

#[AsMessageHandler]
final readonly class CreateRoleHandler
{
    public function __construct(
        private RoleRepository $roles,
        private PermissionResolver $permissions,
        private EntityManagerInterface $em,
        private ScopeContext $scope,
        private SluggerInterface $slugger,
        private SnapshotCollector $snapshots,
    ) {
    }

    /** @return array<string, mixed> */
    public function __invoke(CreateRole $command): array
    {
        $tenantId = $this->scope->tenantId()
            ?? throw new BadRequestHttpException('A role belongs to a tenant; none is in scope.');

        $this->permissions->assertAssignable($command->permissions);

        $role = new Role($tenantId, $this->freeKey($command->name), $command->name);
        $role->define($command->name, $command->permissions, $this->permissions->declared());

        $this->em->persist($role);
        $this->em->flush();
        $this->permissions->forget($tenantId);

        $after = $role->toArray();
        $this->snapshots->after($after);

        return $after;
    }

    /**
     * Readable, because it is what a membership stores and what the audit trail
     * shows: "Front desk" is `front-desk`, a second one `front-desk-2`. Never
     * one of the built-in keys, whatever the name.
     */
    private function freeKey(string $name): string
    {
        $base = substr($this->slugger->slug($name)->lower()->toString(), 0, 32) ?: 'role';
        $key = $base;

        for ($n = 2; \in_array($key, PermissionsInterface::BUILT_IN, true) || $this->roles->byKey($key) !== null; ++$n) {
            $key = $base . '-' . $n;
        }

        return $key;
    }
}
