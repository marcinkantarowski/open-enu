<?php

declare(strict_types=1);

namespace OpenEnu\Kernel\Flags;

/**
 * Runtime configuration and kill switches.
 *
 * Resolution is global default → tenant override. On a single host with no
 * blue/green, turning a feature off without a deploy is how a bad release stops
 * being an outage (.ai/platform/PLAN.md §8.3).
 *
 * Reads are cached and tag-invalidated, so calling this in a hot path is fine.
 */
interface FlagsInterface
{
    public function enabled(string $identifier, bool $default = false): bool;

    public function string(string $identifier, string $default = ''): string;

    public function int(string $identifier, int $default = 0): int;

    /**
     * @param array<string, mixed> $default
     *
     * @return array<string, mixed>
     */
    public function json(string $identifier, array $default = []): array;
}
