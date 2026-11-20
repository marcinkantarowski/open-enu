<?php

declare(strict_types=1);

namespace OpenEnu\Kernel\Health;

use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The realtime hub answers its own health route, on the internal address the
 * API publishes to - not the public one, which a browser uses.
 */
final readonly class MercureCheck implements HealthCheckInterface
{
    public function __construct(private HttpClientInterface $http, private string $mercureUrl)
    {
    }

    public function name(): string
    {
        return 'mercure';
    }

    public function check(): void
    {
        $parts = parse_url($this->mercureUrl);
        if (!\is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            throw new \RuntimeException('MERCURE_URL is not a URL.');
        }

        $base = $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
        $status = $this->http->request('GET', $base . '/healthz', ['timeout' => 2, 'max_duration' => 3])->getStatusCode();

        if ($status !== 200) {
            throw new \RuntimeException(sprintf('/healthz answered %d.', $status));
        }
    }
}
