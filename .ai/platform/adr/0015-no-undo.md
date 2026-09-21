# ADR-0015 - No undo/redo; command snapshots keep it possible

**Status:** accepted · **Date:** 2026-09-10

## Context
The reference framework offers framework-level undo, built on its command pattern: handlers declare
`undo()` and the bus issues undo tokens. Valuable, and a large surface - every handler must
implement a correct inverse, and "correct" gets subtle as soon as two users interleave.

## Decision
No undo. But writes go through a command bus whose audit middleware already captures
before/after snapshots (ADR-0017).

That means the *data* required for undo exists from day one; only the inverse operations and
the token machinery are absent. A project that needs undo adds `undo()` to the handlers that
warrant it, without re-architecting anything.

## Consequences
- Audit answers "what changed" and "what was it before" - most of the value, none of the
  inverse-correctness problem.
- Mistakes are recovered by restore (ADR-0019) or by a compensating action, not by undo.
