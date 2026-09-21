<?php

declare(strict_types=1);

namespace App\Module\Tenant\Security\Voter;

use OpenEnu\Kernel\Contract\TenantScopedInterface;
use OpenEnu\Kernel\Doctrine\ScopeContext;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Refuses a write to another tenant's record.
 *
 * The query filter already prevents *reading* across tenants, which makes this
 * look redundant. It is not: a crafted payload naming another tenant's record id
 * reaches a handler that loaded the entity through a path the filter did not
 * cover (a `getReference`, a cached object, an unscoped admin query). This is
 * the belt to the filter's braces, and the cheap one.
 *
 * @extends Voter<string, TenantScopedInterface>
 */
final class TenantOwnedVoter extends Voter
{
    public const string OWN = 'TENANT_OWNS';

    public function __construct(private readonly ScopeContext $scope)
    {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return $attribute === self::OWN && $subject instanceof TenantScopedInterface;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        \assert($subject instanceof TenantScopedInterface);

        $current = $this->scope->tenantId();

        // Fail closed, like the filter: with no tenant established, nothing is
        // owned. Returning true here would make every unauthenticated path a
        // write primitive.
        return $current !== null && $subject->tenantId() === $current;
    }
}
