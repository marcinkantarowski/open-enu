<?php

declare(strict_types=1);

namespace App\Module\Unit\Command;

use OpenEnu\Kernel\Command\CommandInterface;

final readonly class CreateUnit implements CommandInterface
{
    public function __construct(public ?string $parentId, public string $name, public string $kind)
    {
    }

    public function auditAction(): string
    {
        return 'unit.created';
    }

    public function auditSubjectId(): ?string
    {
        return null;
    }
}
