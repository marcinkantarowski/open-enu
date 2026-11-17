<?php

declare(strict_types=1);

namespace App\Module\Member\Command;

use OpenEnu\Kernel\Command\CommandInterface;

/** Lifts a suspension. */
final readonly class ReactivateMember implements CommandInterface
{
    public function __construct(public string $membershipId)
    {
    }

    public function auditAction(): string
    {
        return 'member.reactivated';
    }

    public function auditSubjectId(): string
    {
        return $this->membershipId;
    }
}
