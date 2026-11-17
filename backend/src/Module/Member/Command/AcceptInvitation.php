<?php

declare(strict_types=1);

namespace App\Module\Member\Command;

use OpenEnu\Kernel\Command\CommandInterface;

/** The invitee uses their link. No session yet: they sign in afterwards, as anyone does. */
final readonly class AcceptInvitation implements CommandInterface
{
    public function __construct(public string $token, public ?string $password, public ?string $displayName)
    {
    }

    public function auditAction(): string
    {
        return 'member.joined';
    }

    public function auditSubjectId(): ?string
    {
        return null;
    }
}
