<?php

declare(strict_types=1);

namespace OpenEnu\Kernel\Dto;

/**
 * The 409 payload.
 *
 * Carries both versions, because a conflict bar that can only say "someone else
 * changed this" leaves the user with no move except reloading and losing their
 * work. Knowing which version they hold and which exists lets the client offer a
 * real choice - reload, overwrite, or show the difference.
 */
final readonly class ConflictBody implements \JsonSerializable
{
    /** @param array<string, mixed> $current the record as it now stands, when cheap to include */
    public function __construct(
        public string $resource,
        public ?string $resourceId,
        public int|string|null $yourVersion,
        public int|string|null $currentVersion,
        public ?array $current = null,
    ) {
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        $body = [
            'resource' => $this->resource,
            'resourceId' => $this->resourceId,
            'yourVersion' => $this->yourVersion,
            'currentVersion' => $this->currentVersion,
        ];

        if ($this->current !== null) {
            $body['current'] = $this->current;
        }

        return $body;
    }
}
