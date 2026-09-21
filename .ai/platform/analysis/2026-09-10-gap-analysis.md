# Gap analysis: what a mature reference framework has that a foundation needs

**Date:** 2026-09-10 · **Subject:** a mature open-source modular-monolith framework - its product page and its repository (44 core modules, 26 packages)

Snapshot of the comparison that produced `.ai/platform/PLAN.md` v3. Kept because the *rejections* matter:
without this record, each one looks like an oversight and gets re-proposed.

## Adopted into the plan

| Capability | Where it landed |
|---|---|
| Attachments / file storage | `StorageInterface` + `Attachment` module - Phase 2b/3 |
| API keys (machine auth) | Third principal, `aud: api_key` - Phase 3 |
| i18n | `pl`+`en`, lint-enforced - Phase 2a/5 (ADR-0020) |
| Optimistic locking | `VersionedInterface`, 409 + conflict bar - Phase 2b |
| Settings + feature flags | `Settings` module, `#[Flag]` - Phase 4 |
| Extension-surface catalogue | `.ai/platform/PLAN.md` §6.10 - Phase 2a |
| Command pattern + audit snapshots | ADR-0017 - Phase 2b |
| Tag-based cache | `TenantCache` - Phase 2b |
| Webhooks (outbound) | `Webhook` module - Phase 7 |
| Structured logs + request correlation | Phase 2a |
| GDPR export/erase | `GdprSubjectInterface` - Phase 2b/3 |
| Progress for long jobs | `Progress` module - Phase 2b/3 |
| Field encryption | Derived keys, no Vault - ADR-0018, Phase 3 |
| Playwright E2E | Phase 5 |
| Tenant setup hooks | `TenantSetupInterface` - Phase 2b/3 |
| Typed events + browser bridge | `#[ClientBroadcast]` + `useAppEvent` - Phase 2b/5 |
| MCP server from OpenAPI + ACL | Phase 9 |
| Search | tsvector only - ADR-0021 |
| Scheduler status view | Phase 4/5 |

## Rejected, with the record

| Capability | Why not | ADR |
|---|---|---|
| Runtime custom fields / entities / query index | EAV + flattening index is a permanent subsystem; migrations are cheap and agents write them well; typed columns are what make OpenAPI and generated types useful | [0013](../adr/0013-no-custom-fields-layer.md) |
| Hierarchical organizations | Doubles the scoping surface on day one; `ScopeFilter` takes N columns so it can be added without a rewrite | [0014](../adr/0014-no-hierarchical-orgs-in-v1.md) |
| Undo/redo | Every handler needs a correct inverse; command snapshots give most of the value | [0015](../adr/0015-no-undo.md) |
| Vault-backed KMS | Would make an external service a prerequisite for booting | [0018](../adr/0018-light-encryption.md) |
| Meilisearch / Qdrant / Chroma | Each is another service to run, back up and keep in sync | [0021](../adr/0021-search-tsvector-only.md) |
| CRM, catalog, sales, workflow engine, dashboards, WMS | That is their product. This ships one reference module and gets out of the way | - |

## The difference that is not a feature

The reference framework is a **versioned package you never fork** - core from npm, your code in
overlays. That is where "zero technical debt on updates" comes from. This repository is a
**template you `make init` and own**.

The models are not reconcilable, but the door does not have to close: the kernel is a
separate package from Phase 0, so a project may later pin a version and pull framework fixes
instead of cherry-picking them. See [ADR-0016](../adr/0016-kernel-packaging.md).
