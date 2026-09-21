<?php

declare(strict_types=1);

namespace OpenEnu\Kernel\Http\Listener;

use OpenEnu\Kernel\Http\RequestId;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Gives every request a correlation id and returns it to the caller.
 *
 * Runs at priority 1024 - before routing, firewalls and everything else - so
 * that a request which fails early still has an id in its logs. An id assigned
 * after the thing that crashed is worth nothing.
 */
final readonly class RequestIdListener
{
    #[AsEventListener(event: KernelEvents::REQUEST, priority: 1024)]
    public function onRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();

        $id = RequestId::sanitize($request->headers->get(RequestId::HEADER))
            ?? RequestId::generate();

        $request->attributes->set(RequestId::ATTRIBUTE, $id);
    }

    #[AsEventListener(event: KernelEvents::RESPONSE, priority: -1024)]
    public function onResponse(ResponseEvent $event): void
    {
        $id = RequestId::fromRequest($event->getRequest());
        if ($id !== null) {
            // Echoed so a user reporting a problem can quote it, and so a
            // frontend can attach it to an error report.
            $event->getResponse()->headers->set(RequestId::HEADER, $id);
        }
    }
}
