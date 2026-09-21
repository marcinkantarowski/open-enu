<?php

declare(strict_types=1);

namespace App\Module\Audit\Service;

use App\Module\Audit\Contract\AuditReaderInterface;
use App\Module\Audit\Repository\AuditEntryRepository;

final readonly class AuditReader implements AuditReaderInterface
{
    public function __construct(private AuditEntryRepository $entries)
    {
    }

    public function recent(?string $tenantId, int $limit = 50): array
    {
        return array_map(
            static fn (object $entry): array => $entry->toArray(),
            $this->entries->recent($tenantId, $limit),
        );
    }
}
