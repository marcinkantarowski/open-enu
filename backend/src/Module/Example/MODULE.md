# Example

**The reference module. Read this one before writing a feature.**

It owns one entity, `Project`, which exists to demonstrate every platform concern exactly
once - and nothing else. It is not a feature anyone needs; delete it when the real modules
outnumber it, and copy it first.

Its budget is **850 LOC including tests** (.ai/platform/PLAN.md §12.1, raised once from 800 when search became a fifteenth concern), checked by `make agents-budget`.
That number is the argument: if a module holding one of everything fits in 850 lines, a
cross-cutting concern in this codebase costs about a line.

> Budget: 8 KB. A module that needs more explaining than that is too big -
> split it rather than writing more here. Checked by `make agents-budget`.

## Owns

- `Project` - one record. Tenant-scoped, versioned, with one encrypted column
  (`clientReference`), one `attributes` JSONB column, and one attachment id.

## Public contracts

Other modules may import **only** what is listed here (from `Contract/`).
Everything else is internal and may change without notice.

- _(none - a reference module publishes no surface for others to couple to.)_

## Events

Events this module emits, which other modules may subscribe to. This list is
cross-checked against `Event/` by `make docs:check`.

- `Event/ProjectCreated.php` - `example.project.created`. Dispatched through the outbox, so
  it commits in the same transaction as the row. Marked `#[ClientBroadcast]`, which puts it
  on this tenant's Mercure topic and makes an open browser update without polling.

## Permissions

- `example.view` - read access
- `example.manage` - create, rename, archive

## Flags

Declared in `Service/ExampleFlags.php`, reconciled by `make flags`:

- `example.archive` - off by default. Gates `POST /api/projects/archive`, which answers
  **404** when off, because "not built yet" and "switched off during an incident" must be
  indistinguishable from outside.

## One of everything, and where to find it

| Concern | File | Cost |
|---|---|---|
| Tenant scoping | `Entity/Project.php` | `implements TenantScopedInterface` |
| Optimistic locking → 409 | `Entity/Project.php`, `Controller/Api/ProjectController.php` | an interface + `$lock->assertCurrent(...)` |
| Field encryption | `Entity/Project.php` | one column type |
| Open-ended data (ADR-0013) | `Entity/Project.php` | a JSONB column |
| Attachment | `Entity/Project.php` | a nullable uuid |
| Write + audit | `Command/`, `Handler/` | a command and a handler |
| Domain event to the browser | `Event/ProjectCreated.php` | `#[ClientBroadcast]` |
| Record-level authorisation | `Security/Voter/ProjectVoter.php` | a voter |
| Feature flag | `Service/ExampleFlags.php` + `#[Flag]` | a declaration and an attribute |
| Background work with progress | `Message/`, `Handler/ArchiveProjectsJobHandler.php` | a job and a handler |
| Impersonation guard | `Controller/Api/ProjectController.php` | `#[DeniedUnderImpersonation]` |
| New-tenant seed | `Setup/ExampleSetup.php` | one interface |
| Deterministic fixtures | `Fixtures/ProjectFixtures.php` | fixed uuids as constants |

## Notes for agents

- Infrastructure this module needs (cache, storage, events, search, progress,
  flags) comes from the kernel - see `.ai/platform/PLAN.md` §6.9. Do not add a local version.
- Cross-module access goes through `Contract/` or an event, never a direct
  import. Enforced by `make arch`.
- **A permission and a voter answer different questions.** `example.manage` asks whether
  this *role* may edit projects; `ProjectVoter` asks whether *this row* may be edited now.
  A permission cannot express the second, because it knows nothing about the record.
- **A command is not a job.** `ArchiveProjects` is synchronous so the caller learns whether
  the request was accepted; the work goes to the jobs transport as `ArchiveProjectsJob`, and
  the browser watches the progress id the command handed back.
- The UI counterpart is `frontend/app/modules/example/`, one Nuxt layer, same name. Its list
  page is the screen to copy.
