<?php

declare(strict_types=1);

namespace OpenEnu\Kernel\Module;

/**
 * A module's `module.yaml`, parsed.
 *
 * Modules are described by data rather than by a PHP class so that discovery
 * works at container-compile time without autoloading application code, and so
 * an agent can read what a module is and depends on without executing anything.
 */
final readonly class ModuleManifest
{
    /** @param list<string> $depends */
    public function __construct(
        public string $name,
        public string $description,
        public array $depends,
        public bool $enabled,
        public string $path,
    ) {
    }

    /** PSR-4 prefix for this module's classes, e.g. `App\Module\Billing\`. */
    public function namespace(): string
    {
        return 'App\\Module\\' . $this->name . '\\';
    }

    /** Absolute path to a subdirectory, whether or not it exists. */
    public function dir(string $sub): string
    {
        return $this->path . '/' . $sub;
    }

    public function hasDir(string $sub): bool
    {
        return is_dir($this->dir($sub));
    }

    /** Lowercase identifier used in permissions, event names and the frontend layer. */
    public function slug(): string
    {
        return strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $this->name) ?? $this->name);
    }
}
