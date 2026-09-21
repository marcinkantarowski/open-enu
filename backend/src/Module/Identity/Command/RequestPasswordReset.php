<?php

declare(strict_types=1);

namespace App\Module\Identity\Command;

use OpenEnu\Kernel\Command\CommandInterface;

final readonly class RequestPasswordReset implements CommandInterface
{
    public function __construct(public string $email)
    {
    }

    public function auditAction(): string
    {
        return 'identity.password.reset_requested';
    }

    public function auditSubjectId(): ?string
    {
        return null;
    }
}
