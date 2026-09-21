<?php

declare(strict_types=1);

namespace App\Module\Identity\Command;

use OpenEnu\Kernel\Command\CommandInterface;

final readonly class RegisterUser implements CommandInterface
{
    public function __construct(
        public string $email,
        public string $plainPassword,
        public string $tenantName,
        public ?string $displayName = null,
        public string $locale = 'en',
    ) {
    }

    public function auditAction(): string
    {
        return 'identity.user.registered';
    }

    public function auditSubjectId(): ?string
    {
        return null;
    }
}
