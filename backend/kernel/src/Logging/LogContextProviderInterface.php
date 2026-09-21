<?php

declare(strict_types=1);

namespace OpenEnu\Kernel\Logging;

/**
 * Contributes fields to every log record.
 *
 * An extension surface (.ai/platform/PLAN.md §6.10): a module that knows something worth
 * having on every line - the current tenant, the acting user, the command being
 * executed - implements this and is picked up by the tag. Nothing has to edit a
 * central processor.
 *
 * Implementations MUST be cheap and MUST NOT throw: they run on every log call,
 * including the one reporting the failure you are trying to diagnose.
 */
interface LogContextProviderInterface
{
    /** @return array<string, scalar|null> */
    public function contribute(): array;
}
