<?php

declare(strict_types=1);

namespace OpenEnu\Kernel\Inventory;

/**
 * Names that look like each other.
 *
 * Advisory, permanently, and the reason is worth stating: two helpers doing the
 * same job rarely share a signature and often do not share a name either, so
 * this can never be more than a hint (.ai/platform/PLAN.md §12.3). A blocking version would
 * be wrong more often than right, and a check that is wrong gets muted - which
 * costs more than the duplicates it was meant to catch.
 *
 * What it is actually good at is the common case: `ProjectArchiver` next to
 * `ArchiveProjectService`, `formatDate` next to `dateFormatter` - a second
 * implementation written by somebody who did not know the first existed.
 */
final readonly class DuplicateNameHint
{
    /** Below this, short names collide by coincidence: `Job` and `Jobs` mean nothing. */
    private const int MIN_LENGTH = 8;

    /** One edit apart is a typo or a plural; three is a different word. */
    private const int MAX_DISTANCE = 2;

    /**
     * @param array<string, mixed> $inventory as produced by InventoryBuilder
     *
     * @return list<string> human-readable pairs, sorted
     */
    public function pairs(array $inventory): array
    {
        $names = [];

        /** @var array<string, array<string, mixed>> $modules */
        $modules = $inventory['modules'] ?? [];

        foreach ($modules as $module => $entry) {
            foreach ($entry as $key => $value) {
                // Only the class-name lists: routes and permissions are
                // deliberately similar to each other and always will be.
                if (!\is_array($value) || \in_array($key, ['routes', 'permissions', 'depends', 'searchable'], true)) {
                    continue;
                }

                foreach ($value as $name) {
                    if (\is_string($name) && \strlen($name) >= self::MIN_LENGTH) {
                        $names[] = [$name, $module . '/' . $key];
                    }
                }
            }
        }

        $pairs = [];

        foreach ($names as $i => [$name, $where]) {
            foreach (\array_slice($names, $i + 1) as [$other, $otherWhere]) {
                if ($name === $other || levenshtein($name, $other) > self::MAX_DISTANCE) {
                    continue;
                }

                $pairs[] = sprintf('%s (%s) ~ %s (%s)', $name, $where, $other, $otherWhere);
            }
        }

        sort($pairs);

        return $pairs;
    }
}
