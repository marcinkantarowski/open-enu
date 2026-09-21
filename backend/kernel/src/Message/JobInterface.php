<?php

declare(strict_types=1);

namespace OpenEnu\Kernel\Message;

/**
 * Marks a message as background work.
 *
 * The counterpart to `DomainEvent`: events announce that something happened,
 * jobs ask for something to be done. Both are routed by one line in
 * `messenger.yaml` - Messenger matches interfaces and parent classes - so a
 * module adds background work without editing a central file.
 *
 * Commands are NOT jobs. A command is synchronous inside a transaction because
 * the caller needs to know whether it worked; a job is dispatched BY a handler
 * once that transaction has committed, for work the caller should not wait on.
 *
 * A job runs in a worker, so it carries no request: the tenant scope and the
 * request id travel as stamps, and anything else it needs must be in the message
 * itself. Put ids in it, never entities.
 */
interface JobInterface
{
}
