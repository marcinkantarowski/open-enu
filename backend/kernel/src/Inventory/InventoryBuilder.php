<?php

declare(strict_types=1);

namespace OpenEnu\Kernel\Inventory;

use OpenEnu\Kernel\Module\ModuleRegistry;
use OpenEnu\Kernel\Search\SearchCatalogue;
use Symfony\Component\Routing\RouterInterface;

/**
 * What already exists, in one machine-readable file.
 *
 * The failure mode this exists for is REINVENTION (.ai/platform/PLAN.md §12.4): an agent
 * writes a second date formatter, a second API wrapper, a third way to page a
 * list, because finding the first one meant knowing what it was called. A
 * directory listing does not answer "is there already something for this?" -
 * a flat index of every declared name does.
 *
 * Built from the filesystem and the router rather than by instantiating
 * services: an inventory that can only be produced by a container that boots is
 * an inventory that is missing exactly when something is broken.
 *
 * Deterministic and sorted, with no timestamp: the file is committed, so any
 * churn in it would be noise in every diff and would eventually be ignored.
 */
final readonly class InventoryBuilder
{
    /**
     * The directories worth indexing, and what a name in each one means.
     *
     * Not every folder: `Migrations` is history, `Tests` is not a surface
     * anything can reuse, and `Dto` is shaped by its endpoint. These are the
     * ones an agent about to write something would want to have already seen.
     */
    private const array SURFACES = [
        'Contract' => 'contracts',
        'Entity' => 'entities',
        'Command' => 'commands',
        'Event' => 'events',
        'Service' => 'services',
        'Repository' => 'repositories',
        'Message' => 'messages',
        'Console' => 'console',
    ];

    public function __construct(
        private ModuleRegistry $modules,
        private RouterInterface $router,
        private SearchCatalogue $search,
    ) {
    }

    /** @return array<string, mixed> */
    public function build(): array
    {
        $permissions = [];
        foreach ($this->modules->permissions() as $permission => $owner) {
            $permissions[$owner][] = $permission;
        }

        $routes = $this->routesByModule();
        $searchable = [];
        foreach ($this->search->all() as $entity => $entry) {
            $searchable[$this->moduleOf($entity) ?? '?'][] = [
                'entity' => $entity,
                'fields' => $entry['fields'],
                'permission' => $entry['permission'],
            ];
        }

        $inventory = [];

        foreach ($this->modules->all() as $name => $module) {
            $entry = [
                'description' => $module['description'],
                'slug' => $module['slug'],
                'depends' => $module['depends'],
            ];

            foreach (self::SURFACES as $dir => $key) {
                $found = $this->classesIn($module['path'] . '/' . $dir);

                if ($found !== []) {
                    $entry[$key] = $found;
                }
            }

            foreach (['permissions' => $permissions, 'routes' => $routes, 'searchable' => $searchable] as $key => $source) {
                if (isset($source[$name])) {
                    $entry[$key] = $source[$name];
                    sort($entry[$key]);
                }
            }

            $inventory[$name] = $entry;
        }

        ksort($inventory);

        return ['modules' => $inventory];
    }

    /**
     * Short class names under a directory, recursively.
     *
     * Short rather than fully-qualified: the namespace is derivable from the
     * module and the folder, and repeating it on every line would triple the
     * file for no added answer.
     *
     * @return list<string>
     */
    private function classesIn(string $dir): array
    {
        if (!is_dir($dir)) {
            return [];
        }

        $names = [];

        /** @var \SplFileInfo $file */
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() === 'php') {
                $names[] = $file->getBasename('.php');
            }
        }

        sort($names);

        return $names;
    }

    /** @return array<string, list<string>> */
    private function routesByModule(): array
    {
        $byModule = [];

        foreach ($this->router->getRouteCollection() as $name => $route) {
            $controller = $route->getDefault('_controller');

            if (!\is_string($controller)) {
                continue;
            }

            $module = $this->moduleOf($controller);

            if ($module === null) {
                continue;
            }

            $byModule[$module][] = sprintf(
                '%s %s (%s)',
                implode('|', $route->getMethods() ?: ['ANY']),
                $route->getPath(),
                $name,
            );
        }

        return $byModule;
    }

    /** The module a fully-qualified `App\Module\X\…` name belongs to, if any. */
    private function moduleOf(string $class): ?string
    {
        return preg_match('/^App\\\\Module\\\\([^\\\\]+)\\\\/', $class, $m) === 1 ? $m[1] : null;
    }
}
