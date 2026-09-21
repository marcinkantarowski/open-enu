<?php

declare(strict_types=1);

namespace OpenEnu\Kernel\Dto;

use Symfony\Component\HttpFoundation\Request;

/**
 * Page/size/sort, parsed and clamped.
 *
 * Clamping is the point. An unbounded `?size=` is a trivial way to make a list
 * endpoint read an entire tenant's table into memory, and every list endpoint
 * would otherwise have to remember to guard it individually.
 */
final readonly class PaginationRequest
{
    public const int DEFAULT_SIZE = 25;
    public const int MAX_SIZE = 100;

    public function __construct(
        public int $page = 1,
        public int $size = self::DEFAULT_SIZE,
        public ?string $sort = null,
        public string $direction = 'asc',
    ) {
    }

    /** @param list<string> $sortable whitelist of sortable fields; anything else is ignored */
    public static function fromRequest(Request $request, array $sortable = []): self
    {
        $page = max(1, $request->query->getInt('page', 1));
        $size = min(self::MAX_SIZE, max(1, $request->query->getInt('size', self::DEFAULT_SIZE)));

        // An unrecognised sort field is dropped rather than rejected: sort is a
        // presentation concern, and failing a whole request over it is hostile.
        // Silently sorting by an arbitrary client-supplied column, on the other
        // hand, is an injection vector - hence the whitelist.
        $sort = $request->query->getString('sort') ?: null;
        if ($sort !== null && $sortable !== [] && !\in_array($sort, $sortable, true)) {
            $sort = null;
        }

        $direction = strtolower($request->query->getString('direction', 'asc'));

        return new self(
            page: $page,
            size: $size,
            sort: $sort,
            direction: $direction === 'desc' ? 'desc' : 'asc',
        );
    }

    public function offset(): int
    {
        return ($this->page - 1) * $this->size;
    }
}
