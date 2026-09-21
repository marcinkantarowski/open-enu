# ADR-0013 - No runtime custom-fields / custom-entities layer

**Status:** accepted · **Date:** 2026-09-10
**Supersedes nothing. Read this before proposing an EAV layer.**

## Context
The architectural reference for this repository - a mature modular-monolith framework - makes a large bet on a
runtime custom-fields layer: admins define fields and entities from the UI with no
migration, backed by EAV storage plus a "query index" that flattens them into read tables
for performance. It is the right bet for a no-code-leaning ERP sold to enterprises.

It is the wrong bet here, for three reasons:
1. A Doctrine migration takes 30 seconds to write and an agent can write it correctly.
2. EAV plus a flattening index is a large, permanently-load-bearing subsystem - exactly the
   kind of thing this repository exists to avoid carrying by default.
3. Typed columns are what make `openapi.json` and generated frontend types useful (ADR-0012).
   Runtime fields are invisible to both.

## Decision
No custom-fields layer. Schema changes are migrations.

**Escape hatch:** an entity that genuinely needs open-ended per-tenant data gets an
`attributes JSONB` column with a GIN index, documented in its `MODULE.md`. The `Example`
module demonstrates one. This covers the real cases (a tenant's own metadata) without the
subsystem.

## Consequences
- Non-developers cannot add fields. Accepted - this is a developer foundation.
- If a project genuinely needs no-code schema editing, it should evaluate a framework
  built around one rather than grow one here.
