<?php

declare(strict_types=1);

namespace OpenEnu\Kernel\Contract;

/**
 * An entity whose concurrent edits are detected rather than silently merged.
 *
 * Required on anything reachable by PUT or PATCH, and checked by an arch test.
 * Without it, two people editing the same record produces last-write-wins: the
 * first person's change vanishes, nobody is told, and the bug is unreproducible
 * because it depends on timing.
 *
 * The entity carries `#[ORM\Version] private int $version`. Doctrine increments
 * it on every flush; `OptimisticLock` compares it against what the client last
 * saw and returns 409 when they differ.
 */
interface VersionedInterface
{
    public function version(): int;
}
