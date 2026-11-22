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
 * Built for a search box that answers while someone types: every word of the
 * query is a prefix and all of them must match, in any order ("kowal jan" finds
 * "Jan Kowalski"), and accents do not count ("zajac" finds "Zając") - the
 * `open_enu_search` configuration (migration Version20200101000002) strips them
 * from documents and queries alike.
 *
 * Tenant scoping is done by hand here, and that is the whole reason the raw-SQL
 * rule exists: this is a DBAL query, so the Doctrine filter cannot see it. Miss
 * the predicate and search returns every tenant's records - with an excerpt.
 */
final readonly class PostgresTsvectorIndexer implements SearchIndexerInterface
{
    /** Characters of content shown whole in an excerpt rather than cut around the match. */
    private const int WHOLE_UP_TO = 160;

    public function __construct(
        private Connection $db,
        private ScopeContext $scope,
        private string $textSearchConfig = 'open_enu_search',
    ) {
    }

    #[Unscoped(reason: 'DBAL upsert; the tenant is passed in and written explicitly below')]
    public function index(string $entityType, string $entityId, array $fields, string $tenantId, bool $unitScoped = false, ?string $unitId = null): void
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
                INSERT INTO search_index (tenant_id, entity_type, entity_id, content, document, indexed_at, unit_scoped, unit_id)
                VALUES (:tenant, :type, :id, :content, to_tsvector(:config, :content), now(), :unitScoped, :unit)
                ON CONFLICT (tenant_id, entity_type, entity_id) DO UPDATE
                SET content = EXCLUDED.content,
                    unit_scoped = EXCLUDED.unit_scoped,
                    unit_id = EXCLUDED.unit_id,
                    document = EXCLUDED.document,
                    indexed_at = EXCLUDED.indexed_at
                SQL,
            [
                'tenant' => $tenantId,
                'type' => $entityType,
                'id' => $entityId,
                'content' => $text,
                'config' => $this->textSearchConfig,
                'unitScoped' => $unitScoped ? 'true' : 'false',
                'unit' => $unitId,
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
        $prefixes = self::prefixQuery($query);
        if ($prefixes === null) {
            return [];
        }

        // A short record - a person's name and contact, an offer's title - is
        // shown whole with the matches marked; a long one is cut to the part
        // around them. Cut short, "Jan Kowalski jan@…" read "Kowalski jan@…".
        $sql = <<<'SQL'
            SELECT entity_type, entity_id,
                   ts_rank(document, q) AS score,
                   CASE WHEN length(content) <= :whole
                        THEN ts_headline(:config, content, q, 'HighlightAll=true')
                        ELSE ts_headline(:config, content, q, 'MaxWords=20, MinWords=5, ShortWord=3')
                   END AS excerpt
            FROM search_index, to_tsquery(:config, :q) AS q
            WHERE tenant_id = :tenant
              AND document @@ q
            SQL;

        $params = [
            'tenant' => $this->tenant(),
            'q' => $prefixes,
            'config' => $this->textSearchConfig,
            'whole' => self::WHOLE_UP_TO,
            'limit' => max(1, min($limit, 100)),
        ];

        $types = [];

        // The units, which the scope filter applies to entities but cannot apply
        // here. A tenant-wide record stays visible; a unit-scoped one only in a
        // unit the scope allows - none, when the scope allows no unit.
        $units = $this->scope->units();
        if ($units === []) {
            $sql .= ' AND NOT unit_scoped';
        } elseif ($units !== null) {
            $sql .= ' AND (NOT unit_scoped OR unit_id IN (:units))';
            $params['units'] = $units;
            $types['units'] = ArrayParameterType::STRING;
        }

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
     * What someone typed, as a `to_tsquery` of prefixes: each word quoted, so the
     * parser keeps an e-mail or a "12-345" whole, and every character `to_tsquery`
     * would read as an operator taken as a space between words - a stray "(" or
     * "'" is a typo, not a syntax error. Null when nothing searchable is left.
     */
    private static function prefixQuery(string $query): ?string
    {
        $words = [];
        foreach (preg_split('/[\s\'\\\\:&|!()<>*]+/u', $query) ?: [] as $word) {
            if (preg_match('/[\p{L}\p{N}]/u', $word) === 1) {
                $words[$word] = "'{$word}':*";
            }
        }

        return $words === [] ? null : implode(' & ', $words);
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
