<?php

declare(strict_types=1);

namespace App\Module\Unit\Contract;

/**
 * What other modules may ask of the tree (ADR-0025). Identity asks it on every
 * request, to turn a membership's units into the set the filter narrows to.
 */
interface UnitTreeInterface
{
    /**
     * These units and every unit below them, in the tenant in scope. Archived
     * units are included: archiving hides a unit from pickers, it does not
     * take its rows away from the people who work with them.
     *
     * @param list<string> $unitIds
     *
     * @return list<string>
     */
    public function expand(array $unitIds): array;
}
