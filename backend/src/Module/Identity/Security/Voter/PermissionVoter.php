<?php

declare(strict_types=1);

namespace App\Module\Identity\Security\Voter;

use App\Module\Identity\Entity\User;
use App\Module\Identity\Service\PermissionResolver;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Decides what a person may do, from their role in the current tenant.
 *
 * Roles are bundles of permissions, not permissions themselves. Code checks
 * `demo.item.create`, never `ROLE_ADMIN`, so adding a role - or moving a
 * capability between roles - touches PermissionResolver and nothing else.
 *
 * The rules themselves live in that resolver rather than here, because the
 * session payload has to answer the same question for the UI. Two copies would
 * drift, and the drift is silent in the worst direction: a button that renders
 * and then 403s.
 *
 * @extends Voter<string, mixed>
 */
final class PermissionVoter extends Voter
{
    public function __construct(private readonly PermissionResolver $permissions)
    {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        // Permission strings are dotted; Symfony roles are SCREAMING_CASE.
        return str_contains($attribute, '.');
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();

        // Abstain for anything that is not a person - an API key's permissions
        // are decided by its own voter, and both must be able to coexist.
        if (!$user instanceof User) {
            return false;
        }

        $tenantId = $user->sessionTenantId();
        if ($tenantId === null) {
            return false;
        }

        $membership = $user->membershipIn($tenantId);
        if ($membership === null) {
            return false;
        }

        return $this->permissions->allows($membership->role(), $attribute);
    }
}
