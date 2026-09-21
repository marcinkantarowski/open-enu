<?php

declare(strict_types=1);

namespace App\Module\ApiKey\Command;

use OpenEnu\Kernel\Command\CommandInterface;

final readonly class CreateApiKey implements CommandInterface
{
    /** @param list<string> $permissions */
    public function __construct(
        public string $name,
        public array $permissions,
        public ?int $expiresInDays = null,
    ) {
    }

    public function auditAction(): string
    {
        return 'api_key.created';
    }

    public function auditSubjectId(): ?string
    {
        return null;
    }
}
