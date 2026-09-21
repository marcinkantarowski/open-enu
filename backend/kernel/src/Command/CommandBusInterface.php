<?php

declare(strict_types=1);

namespace OpenEnu\Kernel\Command;

/**
 * Dispatches a command to its handler, synchronously, inside a transaction.
 *
 * Synchronous on purpose: the caller usually needs the result, and "did this
 * work?" should be answerable at the call site. Work that genuinely belongs in
 * the background is a *message* on the jobs transport, dispatched by the
 * handler - not a command pretending to be one.
 */
interface CommandBusInterface
{
    /**
     * @throws \Throwable whatever the handler threw, after the audit entry is
     *                    recorded as failed and the transaction rolled back
     */
    public function dispatch(CommandInterface $command): mixed;
}
