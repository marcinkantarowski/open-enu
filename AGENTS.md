# Agent Guidelines

This repository is a foundation designed to be **read and extended by AI agents without
creating technical debt**. Two properties make that true, and both are enforced, not hoped:

- **Readable** - paths are *derivable* from a feature name, never discovered by exploration.
- **Extendable without debt** - every rule that matters is also a check that fails.
  Prose is a hint; CI is the contract.

Full design: [`.ai/platform/PLAN.md`](.ai/platform/PLAN.md). Decisions and their rationale: [`.ai/platform/adr/`](.ai/platform/adr/).

> **Instruction budget:** this file must stay under **32 KB** - past that, tools truncate it
> and these rules silently stop applying. Keep hard rules and routing here; put long-form
> procedure in `.ai/platform/docs/*`. Enforced by `make agents-budget`.

---

## Project status

**All nine phases are complete** (.ai/platform/PLAN.md §14). What follows is a description of
what exists, not of what is planned.

The dev stack runs (`make builddev`, ~90s) and the **module system is live**: a directory
under `backend/src/Module/` with a `module.yaml` becomes routed, mapped, migratable,
translated and permission-declaring with no central file edited. `make module NAME=X`
scaffolds it across backend, frontend, docs and the Task Router.

The **guardrails exist and are proved to fail** when broken - four PHPStan architecture
rules (module boundaries, kernel purity, thin controllers, raw SQL) plus structural checks
for modules, docs, locales and context budgets. Every later phase is built under them.

The **platform contracts** exist and work: a command bus that audits every write with
before/after snapshots, tenant-scoped cache and storage, transparent field encryption,
optimistic locking with a 409 carrying both versions, typed domain events, Postgres
full-text search, tenant setup hooks, GDPR export/erase, progress and feature flags.
Read [`.ai/platform/docs/platform-services.md`](.ai/platform/docs/platform-services.md) **before** adding any
infrastructure to a module.

**Eleven modules are live**: Tenant, Identity, ApiKey, Audit, Attachment, Progress,
Manager, Settings, Notification, Webhook and the `Example` reference. A user can register, verify by email, log in, switch tenant and reset
their password; a machine can authenticate with a scoped API key; every write is audited
with before/after snapshots against a real actor and tenant.

Tenant isolation is enforced and tested from several directions at once: a fail-closed query
filter, a voter, a worker stamp, and identity-map clearing. `make test-security` is the
suite that proves it.

**Operators have their own realm.** It is live on its own firewall with its own identity
table: operators administer tenants, read the audit trail across all of them, watch the
worker queues, and impersonate a user through the one designed crossing - a short-lived,
non-refreshable token that is refused on destructive actions and logs both identities.
Settings gives per-tenant kill switches that take effect on the next request.

**The three apps are live and driven by browser tests.** `@open-enu/ui-kit` is the shared
Nuxt layer - one `useApi` that every call goes through, an auth store that holds the access
token in memory only, tenant-scoped realtime, the conflict bar, uploads with real progress,
and the UI primitives. `frontend` and `manager` are SPAs (there is no session to render on
the server); `landing` renders on the server from `@nuxt/content` markdown in both locales.
Each frontend module is a Nuxt layer discovered from the directory, contributing its own
pages, menu entries, settings tabs and translations with no central file edited.

`make e2e` drives twelve tests in five specs through a real browser against the real stack:
cookie login, session restore, tenant switch, impersonation handoff, SSE arrival, upload, the
409 conflict bar, and a feature flag switched on by an operator starting a job with progress. It runs in `make ci`, never in `make check`.

**`Example` is the module to read and to copy.** One entity, `Project`, carrying exactly one
of every platform concern - tenant scoping, optimistic locking, an encrypted column, JSONB
attributes, a command and handler, a broadcast event, a record-level voter, a feature flag, a
background job with progress, an impersonation guard, a tenant seed and deterministic
fixtures. It is capped at **850 lines including tests** by `make agents-budget` and sits just under
that. Adding a demonstration to it now means removing one, or arguing in PLAN §12.1 that the
specification grew.

**Events leave the system and come back as answers.** Outbound webhooks are per-tenant
endpoints subscribed to event names, signed with Standard Webhooks, delivered on the jobs
queue with five retries and a log of every attempt. Notifications are a type registry plus a
per-user feed that stores translation keys, not sentences. Full-text search is one endpoint
across every module that declared a `search.php` - with a permission per entity type, because
a search result is an excerpt and an excerpt is content. Two scheduled tasks run on a clock.

**A bare VPS becomes a running product in one command.** `make buildprod` runs a ten-second
read-only preflight (DNS against the authoritative nameserver, CAA, ports, SSH, server
sanity, and whether the *server* - not your laptop - can read the repository), then installs
Docker, creates the deploy user in lockout-safe order, syncs the code, generates secrets it
will never regenerate, builds, backs up, migrates, health-gates, and installs systemd units.
It refuses to call itself finished on a production box with no off-box backup.

**The repository can be read and driven by an agent.** `.ai/inventory.json` is a committed
index of every declared name, so "does this already exist?" is a grep rather than an
exploration. `app:mcp:serve` offers the API to an MCP client as tools generated from the
committed spec, the router and the ACL - filtered to what a scoped API key can actually
call, and authenticated as one. Every endpoint is proved to have a functional test by
runtime route tracing, unused TypeScript fails the build, and GitHub Actions runs the same
`make ci` a developer runs. See [`.ai/platform/docs/agent-interfaces.md`](.ai/platform/docs/agent-interfaces.md).

The phases are done, which changes nothing about the rules below: a capability that does not
exist yet is still not to be improvised. Say so instead.

| Phase | State |
|---|---|
| 0 - Skeleton, harness, `init`, packaging | **done** |
| 1 - Dev Docker stack | **done** |
| 2a - Kernel: module system + guardrails | **done** |
| 2b - Kernel: platform contracts | **done** |
| 3 - Tenant, Identity, ApiKey, Audit, Attachment, Progress | **done** |
| 4 - Manager realm + Settings/flags | **done** |
| 5 - ui-kit + the three Nuxt apps + Playwright | **done** |
| 6 - The `Example` module, end to end | **done** |
| 7 - Notification, Webhook, Search, scheduler | **done** |
| 8 - Preflight & remote provisioning | **done** (unrun against a real VPS) |
| 9 - Agent-readiness completion, MCP & CI | **done** |

---

## Always

- **Read [`.ai/platform/docs/platform-services.md`](.ai/platform/docs/platform-services.md) before adding any
  infrastructure to a module.** If a module seems to need caching, file storage, encryption,
  search, background progress, feature flags or audit, the kernel already owns that
  contract. Re-implementing one locally is the single most expensive mistake available here.
- **Every state change is a command and a handler.** `flush()` outside a `Handler/` is a
  build failure - that is what makes audit coverage structural rather than remembered.
- Check `.ai/platform/adr/` (and this project's `.ai/adr/`) before changing an architectural
  decision - several deliberate choices look like omissions (no custom-fields layer, no
  hierarchical orgs, no undo).
- Check `.ai/specs/` and `.ai/platform/specs/` for an existing spec before modifying anything
  non-trivial.
- **`.ai/platform/` ships with the platform and is replaced by an update; everything else
  under `.ai/` is the project's.** `.project.json` decides: `"initialized": true` means new
  ADRs, specs, lessons and analyses go in `.ai/adr/`, `.ai/specs/`, … - never in `platform/`.
  Enforced by `make docs-check`. See [ADR-0023](.ai/platform/adr/0023-platform-and-project-knowledge-are-separate-trees.md).
- Write a spec in `.ai/specs/{YYYY-MM-DD}-{kebab-title}.md` before work with 3+ steps or an
  architectural decision.
- Keep changes inside one module. Touching several is an `Ask First`.
- Run `make check` after every edit. It is the inner loop and must stay under 60 seconds.
- When you add a convention, add the check that fails when it is broken - **in the same
  change**. A convention without a check is gone in three months.
- Prefer deleting to deprecating while the repository has no users.

## Ask First

- Reducing scope, changing a public contract, or changing an architectural decision recorded
  in `.ai/platform/adr/`.
- Touching more than one module in a single change.
- Adding a production dependency.
- Anything that mutates a live server, a live DNS zone, or a live database.
- Renaming, moving, or reshaping anything under `backend/kernel/` - it is a versioned
  package with an upgrade contract, not ordinary source.

## Never

- **Never commit, push, merge or open a pull request.** Every commit and every push is made
  by the user and only the user. An agent edits the working tree, runs the checks, reports
  what changed, and stops there - even when a commit looks like the obvious next step.
  Not enforceable from the repository: GitHub cannot tell an agent's push from its user's.
  Enforce it in the agent's own settings - for Claude Code, a `permissions.deny` list for
  `git commit`, `git push` and `gh pr` in `.claude/settings.json`.
- **Never rename the kernel.** `open-enu/kernel`, `OpenEnu\Kernel\` and `@open-enu/ui-kit`
  are the framework's identity and are independent of the project's name. `make init`
  masks them deliberately. Breaking this silently destroys the upgrade path for every
  project built from this repository. Guarded by `make selftest`. See
  [ADR-0016](.ai/platform/adr/0016-kernel-packaging.md).
- Never let anything in `backend/kernel/` import from `App\`. The kernel knows nothing about
  the application. Enforced by `KernelPurityRule`.
- Never hand-roll a module. `make module NAME=X` exists so every module has the same shape;
  one written by hand will differ in ways nobody notices until the third one.
- Never expose cross-tenant data, and never disable tenant scoping outside an explicit,
  reasoned `runUnscoped()` call.
- **Never let unflushed work cross a `runUnscoped()` boundary.** It clears the
  EntityManager, so a change made before the call and flushed after it is flushed on a
  detached entity: no exception, no SQL, no write. Flush first, or write inside the
  callback. Guarded - see [[unflushed-work-does-not-survive-rununscoped]].
- **Never query an encrypted column.** It is ciphertext and differs on every write. Add a
  sibling `*_hash` written with `Encryptor::hashForLookup()` and query that.
- Never use `encrypted_tenant_string` on an entity that is not `TenantScopedInterface` -
  the row becomes unreadable whenever the ambient scope differs from the write.
  See [[an-encryption-key-must-belong-to-the-row]].
- Never mock a collaborator whose *rules* are what you are testing. Caches, storage and
  transports all have in-memory implementations that enforce the real constraints.
  See [[test-contracts-against-the-real-implementation]].
- Never edit a generated file by hand. Files derived from `.env` say `GENERATED` on line 1.
- Never commit a secret, a private key, a certificate, or a `.env`.
- Never hard-code a user-facing string; every one goes through a translation key.
- **Never call `fetch` or `$fetch` from a component.** Every request goes through
  `useApi()`, which is where the in-memory token, the single shared refresh on 401,
  cookie credentials and the typed 409 live. A direct call gets none of them.
- Never set `srcDir` or any other path in a module's `nuxt.config.ts`. `extends` merges a
  layer's config into the APP's, so it moves the app's own pages out from under it - with
  no error, just a router that matches nothing. See [[a-layers-config-is-the-apps-config]].
- Never hand-edit `ui-kit/types/api.d.ts`; it is generated from `backend/openapi.json` by
  `make types` and checked by `make arch`.
- Never add a `#[Flag]` without declaring it in a `FlagProviderInterface`. An undeclared flag
  resolves to the attribute's default forever - invisible in the operator console and
  impossible to turn off during an incident, which is the one job a kill switch has.
  See [[a-mechanism-with-no-way-in-is-untested-by-construction]].
- Never index an encrypted column. `search.php` stores plaintext and returns excerpts of it,
  so indexing one moves the secret into a table with no access control of its own.
- Never write a record and then throw in the same handler. Handlers run inside
  `doctrine_transaction`, so the throw rolls the write back - the log ends up empty about a
  failure that demonstrably happened. See [[a-handler-cannot-record-its-own-failure]].
- Never take the tenant from the ambient scope on a WRITE when the row already knows it.
  Reads fail closed against the scope; writes take it as a parameter, or every legitimate
  `runUnscoped()` caller breaks. See [[an-ambient-dependency-breaks-its-legitimate-callers]].
- Never hard-code a domain. Everything derives from `DOMAIN` in `.env`.
- Never write a long dash (U+2014) - in code, comments, docs or translations. A plain hyphen,
  everywhere. Enforced by `make typography-check`.
- Never weaken a guardrail to make a change pass. Fix the change, or argue the rule should
  go and remove it outright - never mute it for one file.
- Never assert only a status code. Every check asserts content too - a crashed process
  usually still returns *something*, and a 200 has already hidden a fatal error here once.
  See [[status-codes-alone-are-not-a-health-check]].
- **Never name a compose file in a script.** Read `COMPOSE_FILE` through
  `scripts/lib/compose.sh` (`$(COMPOSE)` in the Makefile). On a staging server a hard-coded
  `-f compose.dev.yml` drops the override that keeps the dev stack off the internet.
  Enforced by `make prod-check`.
- Never add a `sleep` to make a step wait. Wait on a healthcheck; a sleep is a race you
  have decided not to look at.
- **Never let a build command mutate DNS or register a credential as a side effect.** It
  diagnoses and generates the fix; `dns-apply` and `deploy-key` are separate, deliberate
  acts (.ai/platform/PLAN.md §10.5).
- Never harden a server before proving you can still log in to it. Create, install the key,
  **open a second connection**, and only then touch `sshd_config`.
- Never regenerate a secret that already exists on a server. `APP_SECRET` invalidates every
  session; `APP_ENCRYPTION_KEY` makes every encrypted column unreadable, permanently.
- Never treat "the tool produced no output" as "the check passed". Distinguish a clean run
  from a run that could not happen - `check-shell.sh` does, after getting it wrong first.

---

## Validation commands

```bash
make check       # THE INNER LOOP - run after every edit. Budget: 60s (currently ~5s)
make ci          # the full gate, before proposing any change
make arch        # every architectural guardrail
make typecheck   # all three Nuxt apps, against the generated API types
make e2e         # the browser suite - needs the stack up; in `ci`, never in `check`
make preflight   # read-only: DNS, ports, SSH, repo access. Changes nothing
make selftest    # proves the guardrails fail when broken
make inventory   # regenerate .ai/inventory.json - what already exists
make dead-code   # unused TypeScript (blocking) + unreachable PHP (advisory)
make modules     # what module discovery actually resolved
make builddev    # bring the whole stack up from nothing (~90s)
```

If `make check` ever exceeds 60 seconds, treat it as a bug in the harness with the same
priority as a failing test: a slow inner loop stops being run, and a guardrail nobody runs
does not exist.

---

## Task Router

Match the task to a row **before** researching or coding; a task often matches several.
This table grows with each phase - a row appears when the thing it routes to exists.

| Task | Where to look |
|---|---|
| **Anything needing infrastructure** (cache, storage, encryption, search, progress, flags, audit, events) | [`.ai/platform/docs/platform-services.md`](.ai/platform/docs/platform-services.md) - the kernel owns the contract |
| Writing data; audit; what the trail records | [`.ai/platform/docs/commands-and-audit.md`](.ai/platform/docs/commands-and-audit.md) |
| Announcing something happened; background jobs; dead letters | [`.ai/platform/docs/events-and-outbox.md`](.ai/platform/docs/events-and-outbox.md) |
| Concurrent edits, 409 conflicts, `If-Match` | `platform-services.md` → *Concurrent edits* |
| Encrypting a column; searching an encrypted one | `platform-services.md` → *Encryption* |
| Per-tenant open-ended data (instead of custom fields) | `platform-services.md` → *Open-ended data* + [ADR-0013](.ai/platform/adr/0013-no-custom-fields-layer.md) |
| Seeding a new tenant | `platform-services.md` → *Tenant setup*, then `make seed` |
| GDPR export / erasure | `platform-services.md` → *GDPR* |
| **Extending without modifying** (decorate, subscribe, override) | [`.ai/platform/docs/extension-surfaces.md`](.ai/platform/docs/extension-surfaces.md) |
| **Adding or changing a module** | [`.ai/platform/docs/module-development.md`](.ai/platform/docs/module-development.md) - start with `make module NAME=X` |
| Reaching another module's data or behaviour | `.ai/platform/docs/extension-surfaces.md` → *Reaching another module* |
| Why a guardrail is failing, and what it wants instead | the error message names the alternative; then `.ai/platform/docs/module-development.md` |
| Renaming the project; what `init` may and may not touch | `scripts/dev/init.sh`, [ADR-0016](.ai/platform/adr/0016-kernel-packaging.md), `make selftest` |
| Environment variables, secrets, derived env files | [`.ai/platform/docs/env-vars.md`](.ai/platform/docs/env-vars.md) |
| Writing a test, or which suite it belongs in | [`.ai/platform/docs/testing.md`](.ai/platform/docs/testing.md) |
| Any user-facing string; catalogues; adding a locale | [`.ai/platform/docs/i18n.md`](.ai/platform/docs/i18n.md) |
| The inventory, the MCP server, what an agent is offered | [`.ai/platform/docs/agent-interfaces.md`](.ai/platform/docs/agent-interfaces.md) |
| Host differences (WSL2 hosts file, cert trust, inotify) | `scripts/lib/os.sh`, `scripts/dev/certs.sh`, `scripts/dev/hosts.sh` |
| The dev stack - services, routing, TLS, healthchecks | `docker/compose.dev.yml`, `.ai/platform/PLAN.md` §8.1 |
| Routing and TLS in dev; two projects on one Docker host | `docker/edge/` + `scripts/dev/edge.sh` - one shared Traefik; name routers `${PROJECT_SLUG}-<service>` |
| **Something in the stack is broken** | [`.ai/platform/docs/troubleshooting.md`](.ai/platform/docs/troubleshooting.md) - symptoms first |
| Adding a service, a host, or a healthcheck | `docker/compose.dev.yml` + add a target to `scripts/dev/smoke.sh` in the same change |
| Why a capability is deliberately absent | `.ai/platform/adr/` - especially 0013 (custom fields), 0014 (hierarchical orgs), 0015 (undo) |
| Tenancy, scope, `runUnscoped()`, what breaks isolation | [`.ai/platform/docs/tenancy.md`](.ai/platform/docs/tenancy.md) |
| Authentication, sessions, tokens, realms, permissions | [`.ai/platform/docs/auth.md`](.ai/platform/docs/auth.md) |
| Machine clients and scoped credentials | `backend/src/Module/ApiKey/MODULE.md` |
| The operator realm, impersonation, cross-tenant reads | `backend/src/Module/Manager/MODULE.md` + `tests/Security/RealmIsolationTest.php` |
| Feature flags, kill switches, per-tenant settings | `backend/src/Module/Settings/MODULE.md` |
| **Writing any feature at all** | `backend/src/Module/Example/` - one of everything, and the thing to copy |
| Declaring a feature flag so an operator can switch it | `backend/src/Module/Example/Service/ExampleFlags.php`, then `make flags` |
| Background work: a job, a worker, a progress bar | `OpenEnu\Kernel\Message\JobInterface` + `Example/Handler/ArchiveProjectsJobHandler.php` |
| **Anything in the three Nuxt apps** | [`.ai/platform/docs/frontend-conventions.md`](.ai/platform/docs/frontend-conventions.md) - then copy `frontend/app/modules/example/pages/example/index.vue` |
| Calling the API from the browser; 401 refresh; typed errors | `ui-kit/app/composables/useApi.ts` |
| Login, tenant switching, what the session holds | `ui-kit/app/stores/auth.ts` + `ui-kit/app/composables/useAuth.ts` |
| Live updates in the UI | `ui-kit/app/composables/useAppEvent.ts` |
| Browser-only behaviour - CORS, cookies, SSE, uploads | `e2e/tests/` + [ADR-0022](.ai/platform/adr/0022-exact-origin-cors.md) + [[a-test-client-never-asks-for-permission]] |
| The landing site - a "coming soon" template; copy, sections, pricing, legal pages | [`.ai/platform/docs/landing.md`](.ai/platform/docs/landing.md) - words live in `landing/content/{en,pl}/*.md` |
| Outbound webhooks, signing, delivery retries | `backend/src/Module/Webhook/MODULE.md` |
| Telling a user something happened | `OpenEnu\Kernel\Notification\NotifierInterface` + `backend/src/Module/Notification/MODULE.md` |
| Making a module's data findable | `backend/src/Module/Example/search.php` - declare fields **and** a permission |
| Background work on a clock | `docker/api/scheduler.sh` + the `Console/` commands it calls |
| Logs, error tracking, traces, "is anything stuck?" | [`.ai/platform/docs/observability.md`](.ai/platform/docs/observability.md) |
| **Deploying anything, anywhere** | [`.ai/platform/docs/deployment.md`](.ai/platform/docs/deployment.md) |
| Staging and production (one server or two); vibe coding on staging; `make promote` | `.ai/platform/docs/deployment.md` → *The loop* + `scripts/lib/stage.sh` |
| DNS, certificates, deploy keys, "it will not provision" | `scripts/remote/preflight.sh` - the failure message names the fix |
| What the production stack may and may not contain | `scripts/dev/check-prod-compose.sh` |
| A mistake that has been made before | `.ai/platform/lessons/` (the platform's) + `.ai/lessons/` (this project's) - read the index, never bulk-read the files |
| Where the platform's records end and this project's begin | [`.ai/README.md`](.ai/README.md) |
| DNS, TLS, deploy keys, provisioning | [`.ai/platform/docs/deployment.md`](.ai/platform/docs/deployment.md) |
| A step-by-step procedure for a whole task | [`.ai/platform/skills/`](.ai/platform/skills/) - create a module, add an endpoint, review a change |
| Tenant - tenant | `backend/src/Module/Tenant/MODULE.md` |
| Identity - identity | `backend/src/Module/Identity/MODULE.md` |
| ApiKey - api_key | `backend/src/Module/ApiKey/MODULE.md` |
| Audit - audit | `backend/src/Module/Audit/MODULE.md` |
| Attachment - attachment | `backend/src/Module/Attachment/MODULE.md` |
| Progress - progress | `backend/src/Module/Progress/MODULE.md` |
| Manager - manager | `backend/src/Module/Manager/MODULE.md` |
| Settings - settings | `backend/src/Module/Settings/MODULE.md` |
| Example - example | `backend/src/Module/Example/MODULE.md` |
| Webhook - webhook | `backend/src/Module/Webhook/MODULE.md` |
| Notification - notification | `backend/src/Module/Notification/MODULE.md` |
<!-- module-rows: make module inserts here, keep this comment -->

---

## Repository map

```
backend/kernel/     open-enu/kernel - the framework. Versioned, never renamed.
backend/src/        App\ - application modules, one folder per feature.
ui-kit/             @open-enu/ui-kit - Nuxt layer shared by the three apps.
frontend/           app.${DOMAIN}      tenant users
manager/            manager.${DOMAIN}  platform operators (separate bundle + firewall)
landing/            ${DOMAIN}          marketing site
e2e/                Playwright - what functional tests cannot see
docker/             compose files, Traefik, Postgres init
scripts/lib/        shared shell library - log, os, require, secrets, envgen
scripts/dev/        local: init, check-tools, info, selftest
scripts/remote/     preflight, provisioning, deploy, backup, restore
.ai/                this project's ADRs, specs, lessons, analyses - never touched by an update
.ai/platform/       the platform's docs, specs, ADRs, lessons, skills - replaced by an update
```

### Where code goes

- Framework, used by every module, knows nothing about the app → `backend/kernel/src/`
- A feature → `backend/src/Module/<Name>/` **and** `frontend/app/modules/<name>/`, one to one
- Shared UI, API client, platform composables → `ui-kit/`
- Anything project-specific in the kernel → **wrong place**; it belongs in a module

Paths are derivable. "Add invoicing" means, with no judgement calls:

```
backend/src/Module/Invoicing/{Contract,Controller/Api,Dto,Entity,Repository,Service,Event,Security/Voter,Acl,Migrations,Fixtures,Tests}
frontend/app/modules/invoicing/{nuxt.config.ts,navigation.ts,i18n/locales/{en,pl}.{json,ts},pages/invoicing,components,composables,stores,types}
.ai/specs/{today}-invoicing.md   (.ai/platform/specs/ in the platform's own repository)
AGENTS.md → a Task Router row
```

*(`make module NAME=Invoicing` generates all of it, then syntax-checks what it wrote. Do not
hand-roll a module: one written by hand differs in ways nobody notices until the third one.)*

---

## Core principles

- **Simplicity first.** The smallest change that fully solves the problem.
- **Find the root cause.** No temporary fixes, no symptom patches.
- **One module per change.** If it spreads, stop and ask.
- **Report honestly.** If tests fail, say so with the output. If a step was skipped, say so.
