<?php

declare(strict_types=1);

namespace App\Module\Role\Command;

use OpenEnu\Kernel\Command\CommandInterface;

/**
 * What a role is called and what it grants. By key, because `admin` and
 * `member` exist before a tenant ever saves them: the first save stores them.
 */
final readonly class UpdateRole implements CommandInterface
{
    /**
     * @param ?string      $name        null on a built-in role: its name in the reader's language
     * @param list<string> $permissions
     */
    public function __construct(public string $key, public ?string $name, public array $permissions)
    {
    }

    public function auditAction(): string
    {
        return 'role.updated';
    }

    public function auditSubjectId(): string
    {
        return $this->key;
    }
}
