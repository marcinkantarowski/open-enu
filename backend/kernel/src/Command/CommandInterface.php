<?php

declare(strict_types=1);

namespace OpenEnu\Kernel\Command;

/**
 * A state change, as data.
 *
 * Every write goes through one (ADR-0017). The marker exists so the bus, the
 * audit middleware and the PHPStan rules can all recognise a write without
 * anyone remembering to register it anywhere.
 *
 * A command is a plain readonly object: the intent, not the mechanism. Its
 * handler does the work.
 */
interface CommandInterface
{
    /**
     * Stable, dotted, past-tense-free: `billing.invoice.void`.
     *
     * This is what the audit trail is queried by, so it is a contract - renaming
     * it orphans history.
     */
    public function auditAction(): string;

    /** The record being changed, when there is one. */
    public function auditSubjectId(): ?string;
}
