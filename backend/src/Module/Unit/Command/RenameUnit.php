<?php

declare(strict_types=1);

namespace App\Module\Unit\Command;

use OpenEnu\Kernel\Command\CommandInterface;

final readonly class RenameUnit implements CommandInterface
{
    public function __construct(public string $id, public string $name, public string $kind)
    {
    }

    public function auditAction(): string
    {
        return 'unit.renamed';
    }

    public function auditSubjectId(): string
    {
        return $this->id;
    }
}
