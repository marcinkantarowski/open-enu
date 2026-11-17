<?php

declare(strict_types=1);

namespace App\Module\Member\Command;

use OpenEnu\Kernel\Command\CommandInterface;

/** A member's role and units, together: they are one decision about what someone may reach. */
final readonly class ChangeMemberAccess implements CommandInterface
{
    /** @param list<string>|null $unitIds */
    public function __construct(public string $membershipId, public string $role, public ?array $unitIds, public ?int $expectedVersion)
    {
    }

    public function auditAction(): string
    {
        return 'member.access_changed';
    }

    public function auditSubjectId(): string
    {
        return $this->membershipId;
    }
}
