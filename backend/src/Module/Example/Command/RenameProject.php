<?php

declare(strict_types=1);

namespace App\Module\Example\Command;

use OpenEnu\Kernel\Command\CommandInterface;

final readonly class RenameProject implements CommandInterface
{
    public function __construct(
        public string $id,
        public string $name,
    ) {
    }

    public function auditAction(): string
    {
        return 'example.project.renamed';
    }

    /** Narrowed from the contract's ?string: a rename always has a subject. */
    public function auditSubjectId(): string
    {
        return $this->id;
    }
}
