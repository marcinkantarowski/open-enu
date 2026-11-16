<?php

declare(strict_types=1);

namespace App\Module\Role\Command;

use OpenEnu\Kernel\Command\CommandInterface;

/** A tenant's own role, while nobody holds it. Built-in roles are never deleted. */
final readonly class DeleteRole implements CommandInterface
{
    public function __construct(public string $key)
    {
    }

    public function auditAction(): string
    {
        return 'role.deleted';
    }

    public function auditSubjectId(): string
    {
        return $this->key;
    }
}
