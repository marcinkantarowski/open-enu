<?php

declare(strict_types=1);

namespace App\Module\Example\Security\Voter;

use App\Module\Example\Entity\Project;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Whether THIS project may be edited.
 *
 * Distinct from the permission check above it, and both are needed:
 * `example.manage` answers "may this role edit projects at all?", while this
 * answers "may this particular row be edited right now?". A permission cannot
 * express the second, because it knows nothing about the record.
 *
 * The rule here is deliberately small - an archived project is read-only - so
 * that the shape is obvious when it is copied for a real one.
 *
 * @extends Voter<string, Project>
 */
final class ProjectVoter extends Voter
{
    public const string EDIT = 'EXAMPLE_PROJECT_EDIT';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return $attribute === self::EDIT && $subject instanceof Project;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        \assert($subject instanceof Project);

        // Nothing about the tenant here: `TenantOwnedVoter` already covers that
        // for every scoped entity, and repeating it would mean two places to fix
        // when the rule changes.
        return !$subject->isArchived();
    }
}
