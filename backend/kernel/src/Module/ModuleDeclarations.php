<?php

declare(strict_types=1);

namespace OpenEnu\Kernel\Module;

/**
 * Reads the plain-PHP files a module uses to declare things.
 *
 * `Acl/permissions.php` and `search.php` are data, not services: they are read
 * at container-compile time, before anything is instantiated, which is why they
 * are `return [...]` files rather than classes.
 *
 * Separated from the extension because that class is wiring - and because this
 * is the half with rules worth stating plainly and failing loudly on. Every
 * error here fires at compile time, so a malformed declaration is a build
 * failure with a file path, not a mystery at runtime.
 */
final readonly class ModuleDeclarations
{
    /**
     * Merge every module's `Acl/permissions.php` into one map.
     *
     * @param array<string, ModuleManifest> $modules
     *
     * @return array<string, string> permission => owning module
     */
    public static function permissions(array $modules): array
    {
        $permissions = [];

        foreach ($modules as $module) {
            foreach (self::read($module, 'Acl/permissions.php', 'an array of permission strings') as $permission) {
                if (!\is_string($permission)) {
                    throw new \RuntimeException(sprintf(
                        '%s must return strings; got %s.',
                        $module->dir('Acl/permissions.php'),
                        get_debug_type($permission),
                    ));
                }

                if (isset($permissions[$permission])) {
                    throw new \RuntimeException(sprintf(
                        'Permission "%s" is declared by both "%s" and "%s". Permissions are global, so each must have exactly one owner.',
                        $permission,
                        $permissions[$permission],
                        $module->name,
                    ));
                }

                $permissions[$permission] = $module->name;
            }
        }

        ksort($permissions);

        return $permissions;
    }

    /**
     * Merge every module's `search.php` into one catalogue.
     *
     *     Project::class => ['fields' => ['name', 'description'], 'permission' => 'example.view']
     *
     * The permission is required rather than optional. Search returns an
     * *excerpt* - actual content - so a hit the caller may not read is a
     * disclosure, and defaulting to "anyone authenticated" would make that the
     * easy path.
     *
     * @param array<string, ModuleManifest> $modules
     *
     * @return array<class-string, array{fields: list<string>, permission: string}>
     */
    public static function searchable(array $modules): array
    {
        $catalogue = [];

        foreach ($modules as $module) {
            $file = $module->dir('search.php');

            /** @var mixed $entry */
            foreach (self::read($module, 'search.php', 'an array keyed by entity class') as $class => $entry) {
                if (!\is_string($class) || !class_exists($class)) {
                    throw new \RuntimeException(sprintf('%s: "%s" is not an entity class.', $file, (string) $class));
                }

                if (!\is_array($entry) || !\is_array($entry['fields'] ?? null) || !\is_string($entry['permission'] ?? null)) {
                    throw new \RuntimeException(sprintf(
                        '%s: %s must declare {fields: string[], permission: string}.',
                        $file,
                        $class,
                    ));
                }

                $catalogue[$class] = [
                    'fields' => array_values(array_map(strval(...), $entry['fields'])),
                    'permission' => $entry['permission'],
                ];
            }
        }

        ksort($catalogue);

        return $catalogue;
    }

    /** @return array<array-key, mixed> */
    private static function read(ModuleManifest $module, string $relative, string $shape): array
    {
        $file = $module->dir($relative);

        if (!is_file($file)) {
            // Absence is legitimate - a module with no permissions or nothing
            // searchable simply does not have the file.
            return [];
        }

        /** @var mixed $declared */
        $declared = require $file;

        return \is_array($declared)
            ? $declared
            : throw new \RuntimeException(sprintf('%s must return %s.', $file, $shape));
    }
}
