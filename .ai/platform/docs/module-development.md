# Module development

A module is one feature, in one directory, on both sides of the stack.

## Create one

```bash
make module NAME=Billing
```

That writes the backend module, the matching frontend layer, `MODULE.md`, a dated spec stub
and the `AGENTS.md` Task Router row - then syntax-checks every PHP file it generated.

Nothing else needs editing. Discovery picks the module up from `module.yaml`; confirm with:

```bash
make modules      # what discovery actually resolved
```

## Anatomy

```
backend/src/Module/Billing/
├── module.yaml          name (== directory name), description, depends, enabled
├── MODULE.md            what it owns; its contracts and events (≤ 8 KB)
├── Contract/            PUBLIC - the only thing other modules may import
├── Controller/          thin: validate → delegate → serialize
├── Dto/                 request DTOs (validated) and response DTOs
├── Entity/              Doctrine entities; mapped per module
├── Repository/          queries; the only place raw SQL may live
├── Service/             the business logic
├── Event/               domain events - the cross-module integration point
├── Listener/ Handler/   reactions, sync and async
├── Message/             async payloads
├── Security/Voter/      authorization decisions
├── Acl/permissions.php  permission strings this module defines
├── Migrations/          this module's schema history
├── Fixtures/            demo data for `make seed`
├── i18n/                messages.en.json, messages.pl.json
└── Tests/{Unit,Functional}/
```

`make module-check` refuses any directory not on that list. An invented one means a
convention was guessed at, and the next module will guess differently.

## The rules, and what enforces them

| Rule | Enforced by |
|---|---|
| Cross a module boundary only via `Contract/` or `Event/` | `ModuleBoundaryRule` |
| Controllers take no `EntityManager` | `ThinControllerRule` |
| Raw SQL only in `Repository/`, only with `#[Unscoped]` | `RawSqlRule` |
| The kernel never imports `App\` | `KernelPurityRule` |
| Every module has `MODULE.md`, `module.yaml`, permissions, both locales, a Task Router row | `make module-check` |
| `MODULE.md` names every `Contract/` and `Event/` class | `make docs-check` |
| Both locales carry the same keys | `make i18n-check` |
| `MODULE.md` stays under 8 KB | `make agents-budget` |

Each rule is proved to fire by a test in `kernel/tests/PHPStan/` or by `make selftest` -
a check nobody has watched fail is not evidence of anything.

## Adding a table

```bash
# 1. write the entity in Entity/
make diff        # generates the migration into THIS module's Migrations/
make migrate
make schema-check
```

Doctrine names migrations by timestamp, so ordering stays globally correct even though each
module keeps its own history.

## Before you propose the change

```bash
make check       # the inner loop, a few seconds
make ci          # the full gate, before committing
```

## Things that will bite

- **A module that needs infrastructure.** Caching, file storage, encryption, search,
  background progress, feature flags, audit - the kernel owns all of it (`.ai/platform/PLAN.md` §6.9).
  Writing a local version is the most expensive mistake available here.
- **`enabled: false` is total.** Routes, services, entities, migrations and permissions all
  disappear. That is the point, and it is why routes are loaded by a module-aware loader.
- **A name/directory mismatch.** The directory name is the PSR-4 namespace segment. The
  loader refuses to boot rather than registering services under a namespace that cannot
  autoload.
- **Two modules declaring the same permission.** A boot failure, deliberately.
