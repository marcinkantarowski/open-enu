<?php

declare(strict_types=1);

namespace OpenEnu\Kernel\Http\Controller;

use OpenEnu\Kernel\Health\HealthCheckInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Liveness and readiness.
 *
 * Two endpoints, because they answer different questions and have different
 * consumers (.ai/platform/PLAN.md §8.2):
 *
 *   /health       shallow - "is this process serving?". Polled by Traefik and
 *                 the container healthcheck every few seconds, so it must not
 *                 touch the database, or a brief DB blip takes the app out of
 *                 the load balancer and turns a hiccup into an outage.
 *
 *   /health/deep  readiness - "are this process's dependencies usable?".
 *                 Polled once by the deploy gate, which must NOT report success
 *                 while the app cannot reach Postgres. Each dependency is a
 *                 HealthCheckInterface (Postgres, Redis, Mercure in the kernel;
 *                 a module may add its own). With none registered it is not
 *                 "ok": a check that verified nothing has not passed.
 *
 * Both are unauthenticated by necessity and therefore rate-limited (Phase 3).
 */
final class HealthController
{
    /** @param iterable<HealthCheckInterface> $checks */
    public function __construct(
        private readonly string $appStage,
        private readonly string $kernelVersion,
        private readonly iterable $checks,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('/health', name: 'kernel_health', methods: ['GET'])]
    public function shallow(): JsonResponse
    {
        return new JsonResponse([
            'status' => 'ok',
            'stage' => $this->appStage,
            'kernel' => $this->kernelVersion,
        ]);
    }

    #[Route('/health/deep', name: 'kernel_health_deep', methods: ['GET'])]
    public function deep(): JsonResponse
    {
        // Name, verdict and time only. Why a check failed goes to the log: this
        // endpoint is public, and an exception message is a map of the inside.
        /** @var array<string, array{ok: bool, ms: int}> $checks */
        $checks = [];

        foreach ($this->checks as $check) {
            $started = hrtime(true);
            try {
                $check->check();
                $ok = true;
            } catch (\Throwable $e) {
                $ok = false;
                $this->logger->error('Health check {check} failed: {message}', [
                    'check' => $check->name(),
                    'message' => $e->getMessage(),
                    'exception' => $e,
                ]);
            }
            $checks[$check->name()] = ['ok' => $ok, 'ms' => intdiv(hrtime(true) - $started, 1_000_000)];
        }

        $failed = array_keys(array_filter($checks, static fn (array $c): bool => !$c['ok']));
        if ($checks === []) {
            $failed = ['nothing-checked'];
        }

        return new JsonResponse(
            [
                'status' => $failed === [] ? 'ok' : 'degraded',
                'stage' => $this->appStage,
                'kernel' => $this->kernelVersion,
                'checks' => (object) $checks,
                'failed' => $failed,
            ],
            $failed === [] ? 200 : 503,
        );
    }
}
