# ADR-0004 - Shared database, `tenant_id`, fail-closed filter

**Status:** accepted · **Date:** 2026-09-10

## Context
Schema-per-tenant isolates better but makes migrations and provisioning heavy. For a
boilerplate, cost of change matters more than maximal isolation.

## Decision
One schema. Tenant-owned entities implement `TenantScopedInterface` and carry an indexed
`tenant_id`. A Doctrine SQL filter scopes every query. **With no tenant in context the
filter emits `1 = 0`** - an unauthenticated route, an un-stamped message or a forgotten CLI
flag returns nothing rather than everything.

Three known bypasses are closed explicitly: native SQL (`#[Unscoped]` only, in
`Repository/`), `getReference()` (banned), and the identity map (`runUnscoped()` clears the
EntityManager on exit, in a `finally`).

## Consequences
- Cheap, one migration set, and a leak requires defeating a default rather than forgetting a `where`.
- Fail-closed means a missing context shows up as "no data", which must be diagnosable -
  hence the `1 = 0` is logged.
