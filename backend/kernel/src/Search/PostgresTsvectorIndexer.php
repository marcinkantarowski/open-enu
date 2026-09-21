<?php

declare(strict_types=1);

namespace OpenEnu\Kernel\Search;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use OpenEnu\Kernel\Attribute\Unscoped;
use OpenEnu\Kernel\Doctrine\ScopeContext;

/**
 * Full-text search on Postgres, with no extra service to run (ADR-0021).
 *
 * One `search_index` table holds a `tsvector` per indexed record, with a GIN
 * index. Adequate well past the point where a project can afford a search
 * cluster, and the interface means swapping in Meilisearch later is one class.
 *
 * Tenant scoping is done by hand here, and that is the whole reason the raw-SQL
 * rule exists: this is a DBAL query, so the Doctrine filter cannot see it. Miss
 * the predicate and search returns every tenant's records - with an excerpt.
 */
final readonly class PostgresTsvectorIndexer implements SearchIndexerInterface
{
    public function __construct(
        private Connection $db,
        private ScopeContext $scope,
        private string $textSearchConfig = 'simple',
    ) {
    }

    #[Unscoped(reason: 'DBAL upsert; the tenant is passed in and written explicitly below')]
    public function index(string $entityType, string $entityId, array $fields, string $tenantId): void
    {
        $text = trim(implode(' ', array_filter($fields, static fn (string $v): bool => $v !== '')));

        if ($text === '') {
            // Nothing to find. Removing rather than storing an empty document
            // keeps a record that lost its indexed text from lingering as a hit
            // that matches nothing.
            $this->remove($entityType, $entityId, $tenantId);

            return;
        }

        $this->db->executeStatement(
            <<<'SQL'
                INSERT INTO search_index (tenant_id, entity_type, entity_id, content, document, indexed_at)
                VALUES (:tenant, :type, :id, :content, to_tsvector(:config, :content), now())
                ON CONFLICT (tenant_id, entity_type, entity_id) DO UPDATE
                SET content = EXCLUDED.content,
                    document = EXCLUDED.document,
                    indexed_at = EXCLUDED.indexed_at
                SQL,
            [
                'tenant' => $tenantId,
                'type' => $entityType,
                'id' => $entityId,
                'content' => $text,
                'config' => $this->textSearchConfig,
            ],
        );
    }

    #[Unscoped(reason: 'DBAL delete; the tenant is passed in and written explicitly below')]
    public function remove(string $entityType, string $entityId, string $tenantId): void
    {
        $this->db->executeStatement(
            'DELETE FROM search_index WHERE tenant_id = :tenant AND entity_type = :type AND entity_id = :id',
            ['tenant' => $tenantId, 'type' => $entityType, 'id' => $entityId],
        );
    }

    #[Unscoped(reason: 'DBAL query; tenant predicate is written explicitly below')]
    public function search(string $query, ?array $entityTypes = null, int $limit = 20): array
    {
        $sql = <<<'SQL'
            SELECT entity_type, entity_id,
                   ts_rank(document, websearch_to_tsquery(:config, :q)) AS score,
                   ts_headline(:config, content, websearch_to_tsquery(:config, :q),
                               'MaxWords=20, MinWords=5, ShortWord=3') AS excerpt
            FROM search_index
            WHERE tenant_id = :tenant
              AND document @@ websearch_to_tsquery(:config, :q)
            SQL;

        $params = [
            'tenant' => $this->tenant(),
            'q' => $query,
            'config' => $this->textSearchConfig,
            'limit' => max(1, min($limit, 100)),
        ];

        $types = [];

        if ($entityTypes !== null && $entityTypes !== []) {
            $sql .= ' AND entity_type IN (:types)';
            $params['types'] = array_values($entityTypes);
            // Expanded by DBAL, never interpolated. An entity type is a FQCN and
            // a Postgres array literal treats `\` as an escape, so hand-building
            // `{App\Module\…}` silently produces `AppModule…` and matches
            // nothing - a filter that quietly returns zero results.
            $types['types'] = ArrayParameterType::STRING;
        }

        $sql .= ' ORDER BY score DESC LIMIT :limit';

        $hits = [];
        foreach ($this->db->fetchAllAssociative($sql, $params, $types) as $row) {
            $hits[] = new SearchHit(
                entityType: (string) $row['entity_type'],
                entityId: (string) $row['entity_id'],
                score: (float) $row['score'],
                excerpt: $row['excerpt'] !== null ? (string) $row['excerpt'] : null,
            );
        }

        return $hits;
    }

    /**
     * Search with no tenant established would read the whole index, so it fails
     * rather than returning everything - the same fail-closed choice the query
     * filter makes, applied where the filter cannot reach.
     */
    private function tenant(): string
    {
        return $this->scope->tenantId() ?? throw new \LogicException(
            'Search requires a tenant in scope. Establish it before indexing or querying - '
            . 'an unscoped search would read every tenant\'s records.',
        );
    }
}
