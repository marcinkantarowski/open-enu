<?php

declare(strict_types=1);

namespace App\Module\Unit\Service;

use App\Module\Unit\Contract\UnitTreeInterface;
use App\Module\Unit\Repository\UnitRepository;

/**
 * Not cached, deliberately: two indexed queries on one tenant's tree, and a
 * moved or narrowed unit then applies to the very next request with nothing
 * to invalidate.
 */
final readonly class UnitTree implements UnitTreeInterface
{
    public function __construct(private UnitRepository $units)
    {
    }

    public function expand(array $unitIds): array
    {
        return $this->units->idsUnder($unitIds);
    }
}
