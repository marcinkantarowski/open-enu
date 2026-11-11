<?php

declare(strict_types=1);

namespace App\Module\Unit\Command;

use OpenEnu\Kernel\Command\CommandInterface;

final readonly class ArchiveUnit implements CommandInterface
{
    public function __construct(public string $id)
    {
    }

    public function auditAction(): string
    {
        return 'unit.archived';
    }

    public function auditSubjectId(): string
    {
        return $this->id;
    }
}
