<?php

declare(strict_types=1);

namespace App\Module\Member\Service;

use App\Module\Identity\Contract\MembershipsInterface;
use App\Module\Role\Contract\PermissionsInterface;
use App\Module\Unit\Contract\UnitTreeInterface;
use OpenEnu\Kernel\Doctrine\ScopeContext;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * What one person may do to another's access (ADR-0025).
 *
 * The rule under all of it: nobody hands out more than they hold. Not a role
 * with a permission they lack - or "member.invite" would be every permission,
 * one invitation to a second address away - and not units beyond their own,
 * or a manager of one site could make someone the whole tenant's.
 */
final readonly class AccessGuard
{
    public function __construct(
        private PermissionsInterface $permissions,
        private UnitTreeInterface $units,
        private MembershipsInterface $memberships,
        private ScopeContext $scope,
        private AuthorizationCheckerInterface $auth,
    ) {
    }

    /** @param list<string>|null $unitIds */
    public function assertGrantable(string $tenantId, string $role, ?array $unitIds): void
    {
        if (!$this->permissions->exists($tenantId, $role)) {
            throw new BadRequestHttpException(sprintf('There is no role "%s" here.', $role));
        }

        if ($unitIds !== null) {
            $unknown = array_values(array_diff($unitIds, $this->units->expand($unitIds)));
            if ($unknown !== []) {
                throw new BadRequestHttpException(sprintf('No such unit: %s.', implode(', ', $unknown)));
            }
        }

        $this->assertWithinCaller($tenantId, $role, $unitIds);
    }

    /**
     * The person being changed must not reach further than the one changing
     * them: an admin does not demote the owner, a site manager does not touch
     * the head office.
     *
     * @param array{role: string, unitIds: list<string>|null} $member
     */
    public function assertManageable(string $tenantId, array $member): void
    {
        $this->assertWithinCaller($tenantId, $member['role'], $member['unitIds']);
    }

    /**
     * A tenant keeps at least one active owner: the role nothing can be taken
     * from, and the only one that can undo any other mistake.
     *
     * @param array{id: string, role: string, status: string} $member
     */
    public function assertNotLastOwner(string $tenantId, array $member): void
    {
        if ($member['role'] !== PermissionsInterface::OWNER || $member['status'] !== 'active') {
            return;
        }

        $owners = array_filter(
            $this->memberships->inTenant($tenantId),
            static fn (array $m): bool => $m['role'] === PermissionsInterface::OWNER && $m['status'] === 'active',
        );

        if (\count($owners) <= 1) {
            throw new ConflictHttpException('This is the last active owner. Make someone else an owner first.');
        }
    }

    /**
     * Whether the caller may change this person at all - what the list offers
     * controls for. The same rule `assertManageable()` enforces, so a pencil
     * is never shown on a row whose save would be refused.
     *
     * @param array{role: string, unitIds: list<string>|null} $member
     */
    public function manageable(string $tenantId, array $member): bool
    {
        return $this->auth->isGranted('member.manage')
            && $this->beyondCaller($tenantId, $member['role'], $member['unitIds']) === null;
    }

    /** @param list<string>|null $unitIds */
    private function assertWithinCaller(string $tenantId, string $role, ?array $unitIds): void
    {
        $refusal = $this->beyondCaller($tenantId, $role, $unitIds);
        if ($refusal !== null) {
            throw new AccessDeniedHttpException($refusal);
        }
    }

    /**
     * Why this role and reach exceed the caller's, or null when they do not.
     *
     * @param list<string>|null $unitIds
     */
    private function beyondCaller(string $tenantId, string $role, ?array $unitIds): ?string
    {
        foreach ($this->permissions->forRole($tenantId, $role) as $permission) {
            if (!$this->auth->isGranted($permission)) {
                return sprintf('That role can "%s", and you cannot.', $permission);
            }
        }

        // null: the caller reaches the whole tenant, so anything goes.
        if ($this->scope->units() === null) {
            return null;
        }

        if ($unitIds === null) {
            return 'You reach only some units, so you cannot give the whole tenant.';
        }

        foreach ($unitIds as $unitId) {
            if (!$this->scope->allowsUnit($unitId)) {
                return 'That unit is outside the ones you reach.';
            }
        }

        return null;
    }
}
