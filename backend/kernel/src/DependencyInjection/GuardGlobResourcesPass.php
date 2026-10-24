<?php

declare(strict_types=1);

namespace OpenEnu\Kernel\DependencyInjection;

use Symfony\Component\Config\Resource\GlobResource;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Puts every glob the container was built from behind a directory-mtime guard.
 *
 * Service discovery registers each module directory with a glob (eight per
 * module), and in debug each is listed again on every request: two hundred
 * listings, a quarter of a second per request through a macOS bind mount, for
 * an answer that only changes when a file is added or removed. See
 * GuardedGlobResource for what still rebuilds the container, and when.
 *
 * Without debug the container tracks no resources and this changes nothing.
 */
final class GuardGlobResourcesPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->isTrackingResources()) {
            return;
        }

        // Keyed as addResource() keys them; getResources() drops the keys.
        $resources = [];
        foreach ($container->getResources() as $resource) {
            $resource = $resource instanceof GlobResource ? new GuardedGlobResource($resource) : $resource;
            $resources[(string) $resource] = $resource;
        }

        $container->setResources($resources);
    }
}
