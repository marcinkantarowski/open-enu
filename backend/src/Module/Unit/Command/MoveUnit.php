<?php

declare(strict_types=1);

namespace App\Module\Unit\Command;

use OpenEnu\Kernel\Command\CommandInterface;

final readonly class MoveUnit implements CommandInterface
{
    public function __construct(public string $id, public ?string $parentId)
    {
    }

    public function auditAction(): string
    {
        return 'unit.moved';
    }

    public function auditSubjectId(): string
    {
        return $this->id;
    }
}
