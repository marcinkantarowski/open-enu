<?php

declare(strict_types=1);

namespace App\Module\Example\Command;

use OpenEnu\Kernel\Command\CommandInterface;

/** Intent, as data. The handler does the work; the bus audits it. */
final readonly class CreateProject implements CommandInterface
{
    /** @param array<string, mixed> $attributes */
    public function __construct(
        public string $name,
        public ?string $description = null,
        public ?string $clientReference = null,
        public array $attributes = [],
    ) {
    }

    public function auditAction(): string
    {
        return 'example.project.created';
    }

    public function auditSubjectId(): ?string
    {
        // The row does not exist yet, and the audit entry is opened before the
        // handler runs. The id arrives in the `after` snapshot instead, which is
        // what ties the entry to the record it created.
        return null;
    }
}
