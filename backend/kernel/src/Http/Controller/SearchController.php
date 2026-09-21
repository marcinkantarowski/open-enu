<?php

declare(strict_types=1);

namespace OpenEnu\Kernel\Http\Controller;

use OpenEnu\Kernel\Search\SearchCatalogue;
use OpenEnu\Kernel\Search\SearchHit;
use OpenEnu\Kernel\Search\SearchIndexerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * One search across every module that opted in.
 *
 * In the kernel rather than in a module because results span modules by
 * definition - a Search module would have to know about all of them, which is
 * the coupling the boundaries exist to prevent. Modules declare what is findable
 * in their own `search.php`; this only asks.
 *
 * Two filters apply, and both matter:
 *   • the tenant, enforced inside the indexer's SQL (ADR-0004);
 *   • the caller's permissions, applied BEFORE the query, so an excerpt of
 *     something they may not read is never fetched.
 */
final readonly class SearchController
{
    private const int MAX_RESULTS = 20;

    public function __construct(
        private SearchIndexerInterface $search,
        private SearchCatalogue $catalogue,
        private Security $security,
    ) {
    }

    #[Route('/api/search', name: 'search', methods: ['GET'])]
    public function __invoke(Request $request): JsonResponse
    {
        $query = trim($request->query->getString('q'));

        if ($query === '') {
            // An empty query is not an error and not "everything" - returning
            // every record for a stray keystroke is how a search box becomes a
            // way to dump the database.
            return new JsonResponse(['items' => [], 'meta' => ['query' => '', 'total' => 0]]);
        }

        $types = $this->catalogue->visibleTo(fn (string $p): bool => $this->security->isGranted($p));

        $hits = $types === []
            ? []
            : $this->search->search($query, $types, self::MAX_RESULTS);

        return new JsonResponse([
            'items' => array_map(
                static fn (SearchHit $hit): array => [
                    'type' => $hit->entityType,
                    'id' => $hit->entityId,
                    'score' => round($hit->score, 4),
                    'excerpt' => $hit->excerpt,
                ],
                $hits,
            ),
            'meta' => ['query' => $query, 'total' => \count($hits)],
        ]);
    }
}
