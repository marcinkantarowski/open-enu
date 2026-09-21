<?php

declare(strict_types=1);

namespace OpenEnu\Kernel\Search;

/**
 * One result. Deliberately an id and a score rather than a loaded entity: the
 * indexer must not have to know how each module loads its own records.
 */
final readonly class SearchHit
{
    public function __construct(
        public string $entityType,
        public string $entityId,
        public float $score,
        public ?string $excerpt = null,
    ) {
    }
}
