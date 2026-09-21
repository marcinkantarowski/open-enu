<?php

declare(strict_types=1);

namespace OpenEnu\Kernel\Messenger;

use OpenEnu\Kernel\Http\RequestId;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;

/**
 * Stamps the correlation id on the way out, restores it on the way in.
 *
 * Both directions matter and they run in different processes:
 *
 *  - Dispatching (web process): copy the current request's id onto the envelope.
 *  - Handling (worker process): there is no HTTP request, so the id is pushed
 *    onto a synthetic one, which is what the log context provider reads. A
 *    worker log line then carries the id of the request that caused the work,
 *    which is the only thing that makes cross-process debugging tractable.
 */
final readonly class RequestIdMiddleware implements MiddlewareInterface
{
    public function __construct(private RequestStack $requests)
    {
    }

    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        $received = $envelope->last(ReceivedStamp::class) !== null;

        if (!$received) {
            if ($envelope->last(RequestIdStamp::class) === null) {
                $request = $this->requests->getCurrentRequest();
                $id = $request !== null ? RequestId::fromRequest($request) : null;
                if ($id !== null) {
                    $envelope = $envelope->with(new RequestIdStamp($id));
                }
            }

            return $stack->next()->handle($envelope, $stack);
        }

        $stamp = $envelope->last(RequestIdStamp::class);
        if (!$stamp instanceof RequestIdStamp) {
            return $stack->next()->handle($envelope, $stack);
        }

        // A worker has no request. Push a synthetic one so everything that reads
        // context from the RequestStack - logging today, tenant scope in Phase 3
        // - works identically in a worker and in a web process.
        $synthetic = new \Symfony\Component\HttpFoundation\Request();
        $synthetic->attributes->set(RequestId::ATTRIBUTE, $stamp->requestId);
        $this->requests->push($synthetic);

        try {
            return $stack->next()->handle($envelope, $stack);
        } finally {
            $this->requests->pop();
        }
    }
}
