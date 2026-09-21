<?php

declare(strict_types=1);

namespace OpenEnu\Kernel\Attribute;

/**
 * Marks a write that legitimately bypasses the command bus.
 *
 * The command bus exists so every state change is audited, transactional and
 * lock-checked (ADR-0017). A small amount of code changes state where none of
 * those apply: session tokens being issued and rotated, a `lastUsedAt` stamp, a
 * progress counter. Auditing those would bury the trail in noise that hides the
 * entries worth reading.
 *
 * The same shape as #[Unscoped], for the same reason: the exception is allowed,
 * it requires a written reason, and it is greppable - "show me every write that
 * skips the audit trail" is one search.
 *
 * If the reason is "this would be tedious as a command", it is not one.
 */
#[\Attribute(\Attribute::TARGET_METHOD | \Attribute::TARGET_CLASS)]
final readonly class InfrastructureWrite
{
    public function __construct(public string $reason)
    {
    }
}
