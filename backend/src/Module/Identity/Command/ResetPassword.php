<?php

declare(strict_types=1);

namespace App\Module\Identity\Command;

use OpenEnu\Kernel\Command\CommandInterface;

final readonly class ResetPassword implements CommandInterface
{
    public function __construct(
        public string $rawToken,
        public string $plainPassword,
    ) {
    }

    public function auditAction(): string
    {
        return 'identity.password.reset';
    }

    public function auditSubjectId(): ?string
    {
        return null;
    }
}
