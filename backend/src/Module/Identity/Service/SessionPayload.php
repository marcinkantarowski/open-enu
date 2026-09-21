<?php

declare(strict_types=1);

namespace App\Module\Identity\Service;

use App\Module\Identity\Entity\User;
use App\Module\Tenant\Contract\TenantReaderInterface;

/**
 * The shape every endpoint that establishes a session returns.
 *
 * Login, refresh, tenant switch and impersonation all produce the same object,
 * so the client has one code path for "I now have a session" rather than four
 * that differ in small ways nobody documented.
 */
final readonly class SessionPayload
{
    public function __construct(
        private TenantReaderInterface $tenants,
        private PermissionResolver $permissions,
    ) {
    }

    /**
     * @return array{user: array<string, mixed>, memberships: list<array<string, mixed>>,
     *     role: ?string, permissions: list<string>}
     */
    public function for(User $user, ?string $tenantId): array
    {
        $memberships = [];

        foreach ($user->memberships() as $membership) {
            // The name comes through the Tenant module's contract, not a join:
            // Membership holds a plain UUID because cross-module associations
            // are a build failure (ADR-0002).
            $tenant = $this->tenants->describe($membership->tenantId());

            $memberships[] = [
                ...$membership->toArray(),
                'tenantName' => $tenant['name'] ?? null,
                'tenantSlug' => $tenant['slug'] ?? null,
            ];
        }

        $role = $tenantId !== null ? $user->membershipIn($tenantId)?->role() : null;

        return [
            'user' => $user->toArray(),
            'memberships' => $memberships,
            'role' => $role,
            // Advisory: it decides which controls render, never which requests
            // succeed. The voter decides that, from the same resolver.
            'permissions' => $role !== null ? $this->permissions->forRole($role) : [],
        ];
    }
}
