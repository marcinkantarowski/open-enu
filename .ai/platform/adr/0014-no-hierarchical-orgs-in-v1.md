# ADR-0014 - No hierarchical organizations in v1; the door stays open

**Status:** accepted · **Date:** 2026-09-10

## Context
The reference framework scopes by `tenantId` **and** `organizationId`, supporting organization trees
inside a tenant. That is genuinely useful (workspaces, departments, franchises) and it
doubles the scoping surface: every query, every token, every voter, every test.

## Decision
v1 scopes by tenant only. But the filter is `ScopeFilter`, written for **N scope columns**
with a column map that currently holds one entry.

Adding `organization_id` later is: a second entry in the map, a claim in the JWT, a column
on the entities that need it. Not a rewrite.

## Consequences
- v1 stays comprehensible; the common case costs nothing.
- The filter carries a small amount of generality it does not yet use - deliberate, and the
  only place in the kernel where that trade is made.
- Revisit when a real project needs workspaces inside a tenant.
