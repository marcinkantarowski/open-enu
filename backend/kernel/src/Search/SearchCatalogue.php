<?php

declare(strict_types=1);

namespace OpenEnu\Kernel\Search;

/**
 * What each module has declared as searchable, merged at compile time.
 *
 * Built from every module's `search.php`, so a module decides what of its own
 * data is findable - and, just as importantly, who may find it. A central list
 * would be a file every feature branch edits and nobody owns.
 */
final readonly class SearchCatalogue
{
    /**
     * @param array<class-string, array{fields: list<string>, permission: string}> $entries
     *     entity class => what to index and what a caller must hold to see it
     */
    public function __construct(private array $entries = [])
    {
    }

    /** @return array<class-string, array{fields: list<string>, permission: string}> */
    public function all(): array
    {
        return $this->entries;
    }

    /** @return array{fields: list<string>, permission: string}|null */
    public function for(string $entityClass): ?array
    {
        return $this->entries[$entityClass] ?? null;
    }

    /**
     * The entity types a caller may see results from.
     *
     * Filtering here rather than after the query: an excerpt is content, so a
     * result the caller may not read must never be fetched in the first place.
     *
     * @param callable(string): bool $granted
     *
     * @return list<class-string>
     */
    public function visibleTo(callable $granted): array
    {
        return array_values(array_filter(
            array_keys($this->entries),
            fn (string $class): bool => $granted($this->entries[$class]['permission']),
        ));
    }
}
