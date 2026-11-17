<?php

declare(strict_types=1);

namespace App\Module\Member\Command;

use OpenEnu\Kernel\Command\CommandInterface;

/** Ask someone to join, at a role and with units. Nothing is granted until they accept. */
final readonly class InviteMember implements CommandInterface
{
    /** @param list<string>|null $unitIds */
    public function __construct(public string $email, public string $role, public ?array $unitIds)
    {
    }

    public function auditAction(): string
    {
        return 'member.invited';
    }

    public function auditSubjectId(): ?string
    {
        return null;
    }
}
