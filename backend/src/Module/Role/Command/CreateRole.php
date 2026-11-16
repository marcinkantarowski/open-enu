<?php

declare(strict_types=1);

namespace App\Module\Role\Command;

use OpenEnu\Kernel\Command\CommandInterface;

/** A tenant's own role. Its key is derived from the name and never changes. */
final readonly class CreateRole implements CommandInterface
{
    /** @param list<string> $permissions */
    public function __construct(public string $name, public array $permissions)
    {
    }

    public function auditAction(): string
    {
        return 'role.created';
    }

    public function auditSubjectId(): ?string
    {
        return null;
    }
}
