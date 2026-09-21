# ADR-0002 - Module boundaries enforced by tooling, not discipline

**Status:** accepted · **Date:** 2026-09-10

## Context
"Don't couple modules" is the kind of rule that holds for three months and then quietly
stops holding, especially when code is generated faster than it is reviewed.

## Decision
Boundaries are PHPStan rules that fail `make arch`:
- Across modules, only another module's `Contract\` and `OpenEnu\Kernel\*` may be imported.
- No cross-module ORM associations, and no cross-module database foreign keys.
- Raw SQL only inside `Repository/`, and only with `#[Unscoped(reason: '…')]`.
- Controllers may not inject `EntityManager`; writes go through the command bus.

## Consequences
- A violation is a build failure, not a review comment.
- Legitimate exceptions must be explicit and greppable (`#[Unscoped]`).
- The rules must be fast; `make check` has a 60-second budget.
