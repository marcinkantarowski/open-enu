<?php

declare(strict_types=1);

namespace OpenEnu\Kernel\Health;

use Symfony\Component\Cache\Adapter\RedisAdapter;

/**
 * Redis answers PING with the password the app has. It carries the job queue,
 * so without it every background job is lost at dispatch.
 */
final readonly class RedisCheck implements HealthCheckInterface
{
    public function __construct(private string $redisUrl)
    {
    }

    public function name(): string
    {
        return 'redis';
    }

    public function check(): void
    {
        // phpredis, as the job transport uses: the extension is in the image.
        $redis = RedisAdapter::createConnection($this->redisUrl, ['class' => \Redis::class, 'timeout' => 2, 'read_timeout' => 2]);
        if (!$redis instanceof \Redis) {
            throw new \RuntimeException('Expected a phpredis connection.');
        }

        $pong = $redis->ping();
        if ($pong !== true && $pong !== '+PONG' && $pong !== 'PONG') {
            throw new \RuntimeException('PING was not answered with PONG.');
        }
    }
}
