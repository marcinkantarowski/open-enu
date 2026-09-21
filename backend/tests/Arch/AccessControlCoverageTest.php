<?php

declare(strict_types=1);

namespace App\Tests\Arch;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Every module endpoint names the permission it needs (.ai/platform/PLAN.md §12.3).
 *
 * The firewall already requires a session under `^/api`, so an action without
 * `#[IsGranted]` is not public - it is reachable by every signed-in user of
 * every role in every tenant, which is an accident rather than a decision. This
 * was claimed as a check for eight phases and did not exist; the scaffolder
 * shipped exactly such an endpoint, and nothing said a word.
 *
 * Walks the real router rather than the source tree: a route is what a client
 * can reach, and an attribute on a method nothing routes to protects nothing.
 */
final class AccessControlCoverageTest extends KernelTestCase
{
    /**
     * Paths where the ABSENCE of a session is the point. Each is declared
     * `security: false` or `PUBLIC_ACCESS` in security.yaml, and each carries
     * its own rate limiter for that reason. Anything else under `/api` must say
     * what it needs.
     */
    private const array PUBLIC_PREFIXES = ['/api/auth/', '/api/manager/login'];

    public function testEveryModuleActionDeclaresThePermissionItNeeds(): void
    {
        self::bootKernel();
        $router = self::getContainer()->get('router');
        \assert($router instanceof RouterInterface);

        $unguarded = [];

        foreach ($router->getRouteCollection() as $name => $route) {
            $controller = $route->getDefault('_controller');

            // Kernel routes (health, storage, realtime) are guarded by the
            // firewall map itself and documented there; this asks about MODULES.
            if (!\is_string($controller) || !str_starts_with($controller, 'App\\Module\\') || !str_contains($controller, '::')) {
                continue;
            }

            foreach (self::PUBLIC_PREFIXES as $prefix) {
                if (str_starts_with($route->getPath(), $prefix)) {
                    continue 2;
                }
            }

            [$class, $method] = explode('::', $controller, 2);
            $reflection = new \ReflectionMethod($class, $method);

            // On the action or on the class - Symfony honours both.
            $declared = $reflection->getAttributes(IsGranted::class) !== []
                || $reflection->getDeclaringClass()->getAttributes(IsGranted::class) !== [];

            if (!$declared) {
                $unguarded[] = sprintf('%-32s %s %s', $name, implode('|', $route->getMethods()), $route->getPath());
            }
        }

        self::assertSame([], $unguarded, sprintf(
            "%d route(s) declare no permission. Add #[IsGranted('<module>.view')] (or `.manage`) to the "
            . "action, or - if it is genuinely public - declare it so in security.yaml and list its prefix "
            . "in this test with the reason.\n  %s",
            \count($unguarded),
            implode("\n  ", $unguarded),
        ));
    }
}
