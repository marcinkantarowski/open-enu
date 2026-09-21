<?php

declare(strict_types=1);

namespace OpenEnu\Kernel\Command;

/**
 * A command that acts ON a tenant from outside it.
 *
 * The audit entry normally takes its tenant from the ambient scope, which is
 * right for ordinary traffic: the actor is inside the workspace they are
 * changing. An operator is not. In the manager realm there is no scope by
 * design (ADR-0007), so without this the most consequential entries in the
 * system - suspensions, impersonations, kill switches flipped during an
 * incident - are written with no tenant at all, and vanish from the one query
 * anybody ever runs: "what happened to this customer?".
 *
 * Separate from `CommandInterface` rather than a method on it, because the
 * answer only exists for commands whose SUBJECT is a tenant. Required to return
 * a string for the same reason: an implementation that could answer null would
 * put the question back where it started.
 */
interface TenantTargetedInterface
{
    /** The tenant this command acts on, whether or not the actor is inside it. */
    public function auditTenantId(): string;
}
