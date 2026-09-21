<?php

declare(strict_types=1);

namespace OpenEnu\Kernel\Http\Controller;

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
 *                 while the app cannot reach Postgres. Checks arrive as the
 *                 dependencies do; today there are none to check.
 *
 * Both are unauthenticated by necessity and therefore rate-limited (Phase 3).
 */
final class HealthController
{
    public function __construct(
        private readonly string $appStage,
        private readonly string $kernelVersion,
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
        // Each dependency registers a check as it is introduced: Postgres and
        // Redis in Phase 2b, Mercure and outbox lag in Phase 7. Until then this
        // reports honestly that it verified nothing, rather than implying it did.
        /** @var array<string, array{ok: bool, detail?: string}> $checks */
        $checks = [];

        $failed = array_keys(array_filter($checks, static fn (array $c): bool => !$c['ok']));

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
