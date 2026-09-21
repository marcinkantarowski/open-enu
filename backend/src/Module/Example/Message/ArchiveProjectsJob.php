<?php

declare(strict_types=1);

namespace App\Module\Example\Message;

use OpenEnu\Kernel\Message\JobInterface;

/**
 * The work itself, on the jobs transport.
 *
 * Ids only, never entities: this is deserialised in a worker process with its
 * own EntityManager, and a serialised entity there is a stale copy of a row that
 * may already have changed.
 *
 * The tenant scope travels as a stamp (`TenantScopeMiddleware`). Without it the
 * worker would run unscoped and the fail-closed filter would turn this into a
 * job that silently archives nothing.
 */
final readonly class ArchiveProjectsJob implements JobInterface
{
    public function __construct(public string $jobId)
    {
    }
}
