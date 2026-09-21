# ADR-0009 - Domain events through a transactional outbox

**Status:** accepted · **Date:** 2026-09-10

## Context
"Cross-module writes go through events" is meaningless without saying *when* the event
fires. Dispatched before `flush()`, a handler in another module acts on uncommitted data.
Dispatched after, a rollback leaves the side effect done. A Redis transport cannot
participate in the database transaction either way.

## Decision
Domain events are Messenger messages on the **Doctrine transport**: the dispatch is written
in the same transaction as the entity change. A worker consumes it and dispatches to
handlers; handlers push heavy fan-out work to the Redis `jobs` transport. The `failed`
transport is Doctrine, so a dead letter is an inspectable row.
`DispatchAfterCurrentBusMiddleware` is on.

## Consequences
- A rolled-back write raises no event; a committed write always does.
- Slightly higher latency than firing in-process - correct, and unnoticeable.
- Two workers instead of one.
