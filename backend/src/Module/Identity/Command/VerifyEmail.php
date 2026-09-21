<?php

declare(strict_types=1);

namespace App\Module\Identity\Command;

use OpenEnu\Kernel\Command\CommandInterface;

/**
 * A command, not a controller side-effect: verification activates an account and
 * its tenant, which is precisely the kind of security event an audit trail is
 * asked about later.
 */
final readonly class VerifyEmail implements CommandInterface
{
    public function __construct(public string $rawToken)
    {
    }

    public function auditAction(): string
    {
        return 'identity.email.verified';
    }

    public function auditSubjectId(): ?string
    {
        // The subject is only known once the token is redeemed.
        return null;
    }
}
