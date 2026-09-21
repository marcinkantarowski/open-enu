<?php

declare(strict_types=1);

namespace OpenEnu\Kernel\Messenger;

use Symfony\Component\Messenger\Stamp\StampInterface;

/**
 * Carries the correlation id of the request that dispatched a message.
 *
 * Without it, tracing anything that crosses into a worker means correlating by
 * timestamp and hoping. With it, one request id finds the HTTP request, every
 * log line it produced, the message it queued, and the worker that handled it.
 */
final readonly class RequestIdStamp implements StampInterface
{
    public function __construct(public string $requestId)
    {
    }
}
