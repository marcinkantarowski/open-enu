<?php

declare(strict_types=1);

namespace App\Module\Identity\Service;

use App\Module\Identity\Contract\UserDirectoryInterface;
use App\Module\Identity\Repository\MembershipRepository;
use App\Module\Identity\Repository\UserRepository;
use OpenEnu\Kernel\Doctrine\ScopeContext;

final readonly class UserDirectory implements UserDirectoryInterface
{
    public function __construct(
        private UserRepository $users,
        private MembershipRepository $memberships,
        private ScopeContext $scope,
    ) {
    }

    public function membersOf(string $tenantId): array
    {
        // Unscoped because the caller is the operator console, which is
        // deliberately outside any tenant - and `User` is not tenant-scoped
        // anyway, so this is about the memberships it joins through.
        return $this->scope->runUnscoped(
            'listing a tenant\'s members for the operator console',
            function () use ($tenantId): array {
                $rows = [];

                foreach ($this->users->membersOf($tenantId) as $user) {
                    $membership = $user->membershipIn($tenantId);

                    $rows[] = [
                        'id' => (string) $user->id(),
                        'email' => $user->email(),
                        'displayName' => $user->displayName(),
                        'role' => $membership?->role() ?? 'unknown',
                        'status' => $user->status(),
                    ];
                }

                return $rows;
            },
        );
    }

    public function countMembersOf(string $tenantId): int
    {
        return $this->scope->runUnscoped(
            'counting a tenant\'s members for the operator console',
            fn (): int => $this->memberships->countForTenant($tenantId),
        );
    }
}
