<?php

declare(strict_types=1);

namespace OpenEnu\Kernel\Module;

/**
 * Runtime view of the discovered modules.
 *
 * The locator runs at compile time; this is what the application asks at
 * runtime ("which modules are loaded?", "what permissions exist?"). It is built
 * from compiled data, so it never touches the filesystem in a request.
 */
final readonly class ModuleRegistry
{
    /**
     * @param array<string, array{description: string, depends: list<string>, path: string, slug: string}> $modules
     * @param array<string, string>                                                                        $permissions permission => owning module
     */
    public function __construct(
        private array $modules,
        private array $permissions,
    ) {
    }

    /** @return list<string> */
    public function names(): array
    {
        return array_keys($this->modules);
    }

    public function has(string $name): bool
    {
        return isset($this->modules[$name]);
    }

    /** @return array<string, array{description: string, depends: list<string>, path: string, slug: string}> */
    public function all(): array
    {
        return $this->modules;
    }

    /** @return array<string, string> permission => owning module */
    public function permissions(): array
    {
        return $this->permissions;
    }

    public function ownerOf(string $permission): ?string
    {
        return $this->permissions[$permission] ?? null;
    }
}
