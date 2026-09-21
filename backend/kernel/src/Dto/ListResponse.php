<?php

declare(strict_types=1);

namespace OpenEnu\Kernel\Dto;

/**
 * The single shape every list endpoint returns.
 *
 * Items live under `items` rather than at the top level so pagination metadata
 * has somewhere to go without a breaking change later - a bare array is a
 * contract you cannot extend.
 *
 * @template T
 */
final readonly class ListResponse implements \JsonSerializable
{
    /** @param list<T> $items */
    public function __construct(
        public array $items,
        public int $total,
        public int $page,
        public int $size,
    ) {
    }

    /**
     * @param list<T> $items
     *
     * @return self<T>
     */
    public static function of(array $items, int $total, PaginationRequest $pagination): self
    {
        return new self($items, $total, $pagination->page, $pagination->size);
    }

    /** @return array{items: list<T>, meta: array{total: int, page: int, size: int, pages: int}} */
    public function jsonSerialize(): array
    {
        return [
            'items' => $this->items,
            'meta' => [
                'total' => $this->total,
                'page' => $this->page,
                'size' => $this->size,
                'pages' => $this->size > 0 ? (int) ceil($this->total / $this->size) : 0,
            ],
        ];
    }
}
