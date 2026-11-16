<?php

declare(strict_types=1);

namespace App\Module\Identity\Service;

use App\Module\Identity\Repository\MembershipRepository;
use App\Module\Role\Contract\RoleHoldersInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/** Memberships are this module's, so it answers who holds a role. */
#[AsAlias(RoleHoldersInterface::class)]
final readonly class RoleHolders implements RoleHoldersInterface
{
    public function __construct(private MembershipRepository $memberships)
    {
    }

    public function holding(string $tenantId, string $role): int
    {
        return $this->memberships->countWithRole($tenantId, $role);
    }
}
