<?php

declare(strict_types=1);

namespace App\Module\Member\Command;

use OpenEnu\Kernel\Command\CommandInterface;

/** Takes away access to this tenant from the next request on; the account and its other tenants are untouched. */
final readonly class SuspendMember implements CommandInterface
{
    public function __construct(public string $membershipId)
    {
    }

    public function auditAction(): string
    {
        return 'member.suspended';
    }

    public function auditSubjectId(): string
    {
        return $this->membershipId;
    }
}
