<?php

declare(strict_types=1);

namespace App\Module\ApiKey\Command;

use OpenEnu\Kernel\Command\CommandInterface;

final readonly class RevokeApiKey implements CommandInterface
{
    public function __construct(public string $keyId)
    {
    }

    public function auditAction(): string
    {
        return 'api_key.revoked';
    }

    public function auditSubjectId(): string
    {
        return $this->keyId;
    }
}
