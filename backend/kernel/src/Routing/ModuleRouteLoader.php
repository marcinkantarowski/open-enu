<?php

declare(strict_types=1);

namespace OpenEnu\Kernel\Routing;

use Symfony\Component\Config\Loader\Loader;
use Symfony\Component\Routing\RouteCollection;

/**
 * Loads route attributes from every enabled module's Controller/ directory.
 *
 * A glob in routes.yaml (`src/Module/*\/Controller/`) would almost work, and
 * would be wrong in one specific way: it cannot see `enabled: false`. Disabling
 * a module must remove its routes along with its services and its schema, or
 * "disabled" means "invisible in the container but still reachable over HTTP".
 *
 * Registered in routes.yaml as:
 *     modules:
 *         resource: .
 *         type: open_enu_modules
 */
final class ModuleRouteLoader extends Loader
{
    /** @param array<string, array{description: string, depends: list<string>, path: string, slug: string}> $modules */
    public function __construct(
        private readonly array $modules,
        ?string $env = null,
    ) {
        parent::__construct($env);
    }

    public function load(mixed $resource, ?string $type = null): RouteCollection
    {
        $collection = new RouteCollection();

        foreach ($this->modules as $module) {
            $dir = $module['path'] . '/Controller';
            if (!is_dir($dir)) {
                continue;
            }

            $imported = $this->import($dir, 'attribute');
            $collection->addCollection($imported);
        }

        return $collection;
    }

    public function supports(mixed $resource, ?string $type = null): bool
    {
        return $type === 'open_enu_modules';
    }
}
