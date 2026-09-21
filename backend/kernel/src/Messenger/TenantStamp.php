<?php

declare(strict_types=1);

namespace OpenEnu\Kernel\Messenger;

use Symfony\Component\Messenger\Stamp\StampInterface;

/**
 * Carries the tenant scope of the request that dispatched a message.
 *
 * Without it, a handler runs with no tenant established. The fail-closed filter
 * then returns nothing, so the job silently does nothing to nobody's data -
 * which is far better than touching the wrong tenant's, and still a bug.
 */
final readonly class TenantStamp implements StampInterface
{
    /** @param array<string, string> $scope */
    public function __construct(public array $scope)
    {
    }
}
