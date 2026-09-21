<?php

declare(strict_types=1);

namespace OpenEnu\Kernel\Search;

/**
 * Full-text search over a module's entities.
 *
 * One built-in driver: a Postgres `tsvector` column with a GIN index. That is
 * adequate well past the point where a project can afford a search cluster, and
 * it costs no extra service to run, back up or keep in sync (ADR-0021).
 *
 * The interface exists so swapping in Meilisearch later is one class.
 */
interface SearchIndexerInterface
{
    /**
     * The tenant is a PARAMETER on writes and ambient only on reads, and the
     * asymmetry is deliberate.
     *
     * A row being indexed knows which tenant it belongs to - it is on the
     * entity. Taking it from the ambient scope instead means a legitimate
     * unscoped write (a fixture, an operator action, a GDPR erasure) either
     * explodes or, worse, indexes into the wrong partition. Reads have no such
     * source of truth, so they stay fail-closed against the scope.
     *
     * @param array<string, string> $fields field => text
     */
    public function index(string $entityType, string $entityId, array $fields, string $tenantId): void;

    public function remove(string $entityType, string $entityId, string $tenantId): void;

    /**
     * @param list<string>|null $entityTypes null searches everything
     *
     * @return list<SearchHit>
     */
    public function search(string $query, ?array $entityTypes = null, int $limit = 20): array;
}
