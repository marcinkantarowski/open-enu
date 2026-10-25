<?php

declare(strict_types=1);

namespace OpenEnu\Kernel\DependencyInjection;

use Symfony\Component\Config\Resource\GlobResource;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Puts every glob the container was built from behind one throttle.
 *
 * Service discovery registers each module directory with a glob (eight per
 * module), and in debug each is listed again on every request: two hundred
 * listings, a quarter of a second per request through a macOS bind mount, for
 * an answer that only changes when a file is added or removed. See
 * ThrottledGlobResources for how late that answer may now be.
 *
 * Without debug the container tracks no resources and this changes nothing.
 */
final class ThrottleGlobResourcesPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->isTrackingResources()) {
            return;
        }

        // Keyed as addResource() keys them; getResources() drops the keys.
        $resources = [];
        $globs = [];
        foreach ($container->getResources() as $resource) {
            if ($resource instanceof GlobResource) {
                $globs[] = $resource;
            } else {
                $resources[(string) $resource] = $resource;
            }
        }

        if ($globs !== []) {
            $cacheDir = $container->getParameter('kernel.cache_dir');
            \assert(\is_string($cacheDir));
            $throttled = new ThrottledGlobResources($globs, $cacheDir . '/globs-checked');
            $resources[(string) $throttled] = $throttled;
        }

        $container->setResources($resources);
    }
}
