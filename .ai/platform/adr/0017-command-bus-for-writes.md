# ADR-0017 - Every write goes through a command bus

**Status:** accepted · **Date:** 2026-09-10

## Context
`.ai/platform/PLAN.md` §6.7 promises an audit log recording "every mutating command". Without a uniform
write path that promise is aspirational: each controller audits or forgets to, and the
forgetting is invisible.

## Decision
Mutations are `Command` objects handled by a handler, dispatched on a Messenger **sync** bus.
An `AuditMiddleware` wraps every dispatch and records who, which tenant, which command,
before/after snapshot, request id, and whether it ran under impersonation.
`flush()` is callable only from handlers and the kernel - a PHPStan rule.

## Consequences
- Audit coverage is structural rather than remembered.
- Controllers become genuinely thin: validate, dispatch, serialize.
- Undo stays possible without being built (ADR-0015).
- Slightly more ceremony for a trivial write. Accepted; the reference module shows the
  minimum shape.
