<?php

declare(strict_types=1);

namespace OpenEnu\Kernel\Health;

use Doctrine\DBAL\Connection;

/** Postgres answers a query - not merely accepts a connection. */
final readonly class DatabaseCheck implements HealthCheckInterface
{
    public function __construct(private Connection $connection)
    {
    }

    public function name(): string
    {
        return 'database';
    }

    public function check(): void
    {
        if ((int) $this->connection->fetchOne('SELECT 1') !== 1) {
            throw new \RuntimeException('SELECT 1 did not return 1.');
        }
    }
}
