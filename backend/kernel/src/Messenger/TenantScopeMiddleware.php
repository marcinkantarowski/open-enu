<?php

declare(strict_types=1);

namespace OpenEnu\Kernel\Messenger;

use OpenEnu\Kernel\Doctrine\ScopeContext;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;

/**
 * Stamps the tenant on dispatch; re-establishes it on handling.
 *
 * This is the single most common source of tenant leaks in async code, and the
 * reason it is a middleware rather than something each handler does: a handler
 * that forgets is indistinguishable from one that had nothing to do.
 *
 * The scope is restored afterwards, not merely cleared. A worker handles
 * messages for many tenants in one long-lived process, so leaving the last
 * message's tenant in place would scope the next one wrongly - and the next one
 * might have no stamp at all, in which case it would silently inherit.
 */
final readonly class TenantScopeMiddleware implements MiddlewareInterface
{
    public function __construct(private ScopeContext $scope)
    {
    }

    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        $received = $envelope->last(ReceivedStamp::class) !== null;

        if (!$received) {
            if ($envelope->last(TenantStamp::class) === null && $this->scope->all() !== []) {
                $envelope = $envelope->with(new TenantStamp($this->scope->all()));
            }

            return $stack->next()->handle($envelope, $stack);
        }

        $stamp = $envelope->last(TenantStamp::class);
        $previous = $this->scope->all();

        if ($stamp instanceof TenantStamp) {
            $this->scope->enter($stamp->scope);
        }

        try {
            return $stack->next()->handle($envelope, $stack);
        } finally {
            $this->scope->enter($previous);
        }
    }
}
