<?php

declare(strict_types=1);

namespace OpenEnu\Kernel\Flags;

/**
 * Flags from configuration, before the Settings module exists.
 *
 * A real default rather than "everything is off": code written now can use
 * `#[Flag]` and `FlagsInterface` immediately, and the Settings module (Phase 4)
 * decorates this with per-tenant overrides without a single call site changing.
 */
final readonly class StaticFlags implements FlagsInterface
{
    /** @param array<string, mixed> $values */
    public function __construct(private array $values = [])
    {
    }

    public function enabled(string $identifier, bool $default = false): bool
    {
        $value = $this->values[$identifier] ?? null;

        return match (true) {
            $value === null => $default,
            \is_bool($value) => $value,
            \is_string($value) => \in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true),
            \is_int($value) => $value !== 0,
            default => $default,
        };
    }

    public function string(string $identifier, string $default = ''): string
    {
        $value = $this->values[$identifier] ?? null;

        return \is_string($value) ? $value : $default;
    }

    public function int(string $identifier, int $default = 0): int
    {
        $value = $this->values[$identifier] ?? null;

        return \is_int($value) ? $value : (\is_string($value) && ctype_digit($value) ? (int) $value : $default);
    }

    public function json(string $identifier, array $default = []): array
    {
        $value = $this->values[$identifier] ?? null;

        return \is_array($value) ? $value : $default;
    }
}
