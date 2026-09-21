<?php

declare(strict_types=1);

namespace OpenEnu\Kernel\Module;

use Symfony\Component\Yaml\Yaml;

/**
 * Finds modules on disk and validates their manifests.
 *
 * Runs at container-compile time, so it must not autoload or instantiate any
 * application class - only read files. Everything downstream (services, routes,
 * ORM mappings, migrations, permissions) is derived from what this returns, so
 * a mistake here is a mistake everywhere: it validates loudly rather than
 * skipping a malformed module and leaving you to wonder why it never loaded.
 */
final class ModuleLocator
{
    private const array REQUIRED_KEYS = ['name', 'description'];

    public function __construct(private readonly string $modulesDir)
    {
    }

    /**
     * @return array<string, ModuleManifest> keyed by module name, dependency-ordered
     *
     * @throws \RuntimeException on a malformed manifest, a name mismatch, an
     *                           unknown dependency, or a dependency cycle
     */
    public function locate(): array
    {
        if (!is_dir($this->modulesDir)) {
            return [];
        }

        $all = [];
        foreach ((glob($this->modulesDir . '/*/module.yaml') ?: []) as $file) {
            $manifest = $this->parse($file);
            if (isset($all[$manifest->name])) {
                throw new \RuntimeException(sprintf('Duplicate module name "%s".', $manifest->name));
            }
            $all[$manifest->name] = $manifest;
        }

        $enabled = array_filter($all, static fn (ModuleManifest $m): bool => $m->enabled);
        $this->assertDependenciesResolvable($enabled, $all);

        return $this->sortByDependency($enabled);
    }

    private function parse(string $file): ModuleManifest
    {
        $dir = \dirname($file);
        $expected = basename($dir);

        try {
            $data = Yaml::parseFile($file);
        } catch (\Throwable $e) {
            throw new \RuntimeException(sprintf('%s is not valid YAML: %s', $file, $e->getMessage()), 0, $e);
        }

        if (!\is_array($data)) {
            throw new \RuntimeException(sprintf('%s must contain a YAML mapping.', $file));
        }

        foreach (self::REQUIRED_KEYS as $key) {
            if (!isset($data[$key]) || !\is_string($data[$key]) || $data[$key] === '') {
                throw new \RuntimeException(sprintf('%s is missing the required "%s" key.', $file, $key));
            }
        }

        // The directory name IS the namespace segment, so a mismatch would
        // produce services under a namespace that does not autoload - a failure
        // that surfaces far from its cause.
        if ($data['name'] !== $expected) {
            throw new \RuntimeException(sprintf(
                '%s declares name "%s" but lives in directory "%s". They must match: '
                . 'the directory name is the PSR-4 namespace segment.',
                $file,
                $data['name'],
                $expected,
            ));
        }

        $depends = $data['depends'] ?? [];
        if (!\is_array($depends)) {
            throw new \RuntimeException(sprintf('%s: "depends" must be a list of module names.', $file));
        }

        return new ModuleManifest(
            name: $data['name'],
            description: $data['description'],
            depends: array_values(array_map(strval(...), $depends)),
            enabled: (bool) ($data['enabled'] ?? true),
            path: $dir,
        );
    }

    /**
     * @param array<string, ModuleManifest> $enabled
     * @param array<string, ModuleManifest> $all
     */
    private function assertDependenciesResolvable(array $enabled, array $all): void
    {
        foreach ($enabled as $module) {
            foreach ($module->depends as $dep) {
                if (!isset($all[$dep])) {
                    throw new \RuntimeException(sprintf(
                        'Module "%s" depends on "%s", which does not exist. '
                        . 'Available: %s',
                        $module->name,
                        $dep,
                        implode(', ', array_keys($all)) ?: '(none)',
                    ));
                }
                if (!isset($enabled[$dep])) {
                    throw new \RuntimeException(sprintf(
                        'Module "%s" depends on "%s", which is disabled in its module.yaml. '
                        . 'Enable it, or drop the dependency.',
                        $module->name,
                        $dep,
                    ));
                }
            }
        }
    }

    /**
     * Depth-first topological sort: a module always appears after everything it
     * depends on. Setup hooks, seeding and migrations all rely on that order.
     *
     * @param array<string, ModuleManifest> $modules
     *
     * @return array<string, ModuleManifest>
     */
    private function sortByDependency(array $modules): array
    {
        $sorted = [];
        $state = [];

        $visit = function (string $name, array $trail) use (&$visit, &$sorted, &$state, $modules): void {
            if (($state[$name] ?? null) === 'done') {
                return;
            }
            if (($state[$name] ?? null) === 'visiting') {
                throw new \RuntimeException(sprintf(
                    'Circular module dependency: %s -> %s',
                    implode(' -> ', $trail),
                    $name,
                ));
            }
            $state[$name] = 'visiting';
            foreach ($modules[$name]->depends as $dep) {
                $visit($dep, [...$trail, $name]);
            }
            $state[$name] = 'done';
            $sorted[$name] = $modules[$name];
        };

        foreach (array_keys($modules) as $name) {
            $visit($name, []);
        }

        return $sorted;
    }
}
