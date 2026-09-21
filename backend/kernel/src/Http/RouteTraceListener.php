<?php

declare(strict_types=1);

namespace OpenEnu\Kernel\Http;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;

/**
 * Records which routes the test suite actually hits.
 *
 * The alternative - grepping tests for route names - measures whether somebody
 * wrote the name down, not whether the endpoint was exercised. This measures
 * what ran, which is the only thing that means anything.
 *
 * Active only when `ROUTE_TRACE` names a file, so it costs nothing outside the
 * coverage run and cannot be on in production by accident.
 *
 * Appends rather than buffering: the functional suite reboots the kernel
 * hundreds of times, and anything held in memory dies with each one.
 */
#[AsEventListener(event: RequestEvent::class, priority: -128)]
final readonly class RouteTraceListener
{
    public function __invoke(RequestEvent $event): void
    {
        $file = $_SERVER['ROUTE_TRACE'] ?? $_ENV['ROUTE_TRACE'] ?? null;

        if (!\is_string($file) || $file === '') {
            return;
        }

        $route = $event->getRequest()->attributes->get('_route');

        if (!\is_string($route) || $route === '') {
            // A request that matched nothing. Recording it would make a 404 in
            // a test look like coverage of whatever route was meant to be hit.
            return;
        }

        // LOCK_EX because the suite may run in parallel workers, and a half
        // written line reads as a route nobody can find.
        file_put_contents($file, $route . "\n", \FILE_APPEND | \LOCK_EX);
    }
}
