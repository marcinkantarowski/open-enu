<?php

declare(strict_types=1);

namespace App\Module\Tenant\Command;

use OpenEnu\Kernel\Command\CommandInterface;

final readonly class CreateTenant implements CommandInterface
{
    public function __construct(
        public string $slug,
        public string $name,
        public string $defaultLocale = 'en',
    ) {
    }

    public function auditAction(): string
    {
        return 'tenant.created';
    }

    public function auditSubjectId(): ?string
    {
        return null; // The id does not exist until the handler runs.
    }
}
