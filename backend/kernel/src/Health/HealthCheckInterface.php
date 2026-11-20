<?php

declare(strict_types=1);

namespace OpenEnu\Kernel\Health;

/**
 * One dependency the deep health check proves usable.
 *
 * Throw when it is not. The message goes to the log, never to the response:
 * /health/deep is public, and "connection refused to postgres:5432 as user x"
 * is a map of the inside. A module adds a check by implementing this interface;
 * it is autoconfigured, and the endpoint lists it by name().
 *
 * Keep each check cheap and bounded - the deploy gate polls this, and a check
 * that hangs makes a healthy release look broken.
 */
interface HealthCheckInterface
{
    /** Short and stable - it is the key in the response, e.g. "database". */
    public function name(): string;

    /** @throws \Throwable when the dependency is not usable */
    public function check(): void;
}
