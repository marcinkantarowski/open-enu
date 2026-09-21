<?php

declare(strict_types=1);

namespace OpenEnu\Kernel\Command;

use Symfony\Contracts\Service\ResetInterface;

/**
 * Where a handler leaves "what it looked like before" and "after".
 *
 * A Messenger middleware cannot reach the handler instance, so snapshots cannot
 * be pulled out of it - they have to be pushed in. A handler that wants its
 * change to be reconstructable injects this and calls `before()` while the old
 * state is still loaded.
 *
 * Explicit on purpose: a handler serialising an entity is the only code that
 * knows which fields are worth keeping and which are noise or secrets.
 *
 * Request-scoped and reset between commands, so one handler's snapshot can
 * never attach itself to the next command's audit entry.
 */
final class SnapshotCollector implements ResetInterface
{
    /** @var array<string, mixed>|null */
    private ?array $before = null;

    /** @var array<string, mixed>|null */
    private ?array $after = null;

    /** @param array<string, mixed> $state */
    public function before(array $state): void
    {
        $this->before = $state;
    }

    /** @param array<string, mixed> $state */
    public function after(array $state): void
    {
        $this->after = $state;
    }

    /** @return array{0: array<string, mixed>|null, 1: array<string, mixed>|null} */
    public function take(): array
    {
        $pair = [$this->before, $this->after];
        $this->reset();

        return $pair;
    }

    public function reset(): void
    {
        $this->before = null;
        $this->after = null;
    }
}
