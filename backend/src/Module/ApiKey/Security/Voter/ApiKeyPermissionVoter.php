<?php

declare(strict_types=1);

namespace App\Module\ApiKey\Security\Voter;

use App\Module\ApiKey\Security\ApiKeyUser;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Grants a permission only if the key was issued with it.
 *
 * This is what makes a key's permission list a genuine subset rather than
 * decoration: an integration created by an owner does not inherit the owner's
 * authority, so a read-only reporting key cannot delete anything even though the
 * person who made it could.
 *
 * @extends Voter<string, mixed>
 */
final class ApiKeyPermissionVoter extends Voter
{
    protected function supports(string $attribute, mixed $subject): bool
    {
        // Permission strings are dotted (`demo.item.create`); Symfony roles are
        // SCREAMING_CASE. The dot is what distinguishes them.
        return str_contains($attribute, '.');
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();

        // Only opinionated about keys. A human's permissions are decided by the
        // Identity module's own voter; abstaining here lets both coexist.
        if (!$user instanceof ApiKeyUser) {
            return false;
        }

        return \in_array($attribute, $user->permissions(), true);
    }
}
