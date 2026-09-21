# OpenEnu - Project Boilerplate Plan

**Version 3.6** - v2 applied the architecture/security review; v3 folds in the platform
services that a gap analysis against a mature reference framework showed a foundation needs in month one,
front-loaded into Phases 2–5. v3.1 closes every open question. v3.2–v3.6 record what
building Phases 0–9 changed in the design. Changelogs are in §17.

A single-repo, multi-app boilerplate for starting new products fast: Symfony 7.4 API +
three Nuxt 4 apps + Docker/Traefik, with one `make` command for local dev and one for
provisioning a fresh staging/production server over SSH.

The stack is taken from an earlier production project (proven, running in production).
The architecture, conventions and agent-facing documentation discipline are taken from
a mature open-source reference framework (modular monolith, strict conventions, task-routed `AGENTS.md`).

> Status: **all nine phases built**. This document is the design, and §17 records where
> building it changed the design. The remote provisioning scripts (§10) have not been run
> against a real VPS; everything else is exercised by `make ci`.

---

## Design thesis

**OpenEnu is a foundation designed to be read and extended by AI agents without creating
technical debt.** That is the requirement everything else serves - the module system, the
naming, the docs layout, the CI gates all exist for it.

It is two distinct engineering problems, and this plan treats them separately:

- **Readable** means an agent can answer *"where does this go?"* and *"what's the pattern?"*
  by reading a small, **predictable** set of files - not by grepping a repo it cannot fit in
  context. Paths must be **derivable** from the feature name, not discovered by exploration.
- **Extendable without debt** means the tenth feature an agent adds costs the same as the
  first. Conventions that live only in prose decay silently under generated code, so **every
  rule that matters here is also a check that fails**. Prose is a hint; CI is the contract.

The corollary, and the single most important design constraint below: *the reference module
is the specification*. An agent is pointed at one folder that already does everything
correctly, rather than at documentation describing it.

§12 says how both properties are built and enforced - and, as of v2, the checks that
enforce them are built **first** (Phase 2), so every later phase is developed under them.

---

## 1. Goals

1. **`make builddev` on a fresh laptop** (Linux, macOS, or WSL2) → whole stack running on
   trusted-HTTPS local domains, DB migrated, demo data seeded, in one command.
2. **`make buildprod HOST=deploy@1.2.3.4 DOMAIN=open-enu.com`** on a bare VPS → Docker
   installed, repo deployed, secrets generated, Let's Encrypt certs issued, all
   subdomains live, migrations run, off-box backups scheduled. One command, idempotent,
   re-runnable.
3. **Readable and extendable by agents, without debt** - the design thesis above. An
   agent opening the repo finds a task router, a reference module that *is* the spec,
   per-module docs, a scaffolding command, and a set of checks that fail the moment a
   convention is broken. Adding a feature means adding one self-contained module, not
   touching ten shared folders. Measured by §12's budgets and enforced by §12.3's gates.
4. **SOLID by construction.** Module boundaries enforced by tooling, not by discipline:
   no cross-module ORM relations, no cross-module DB foreign keys, DI-injected contracts,
   ACL and tenant scoping applied centrally so they cannot be forgotten.
5. **Multi-tenant from day one**, with users who can belong to several tenants, and the
   platform-operator surface on its own subdomain, its own app bundle, its own token
   audience and its own security firewall.
6. **`make init NAME=myproject`** turns the boilerplate into a new project in one command -
   namespaces, container names, DB names, domains. A boilerplate you have to hand-edit is
   a template you fight.
7. **Platform services included, thin.** The cross-cutting things every SaaS rebuilds ad hoc
   in month one - file storage, API keys, i18n, settings + feature flags, optimistic
   locking, webhooks, progress for long jobs, GDPR export/erase, field encryption,
   structured logs with request correlation - ship as kernel services and small modules,
   each with one contract and one reference use in `Example`. §6.9 lists them.
8. **Updatable kernel.** The framework layer (v2's `Core` + `Shared`) lives in an internal composer package
   (`open-enu/kernel`) and `ui-kit` in an internal npm package from Phase 0 - path-installed,
   zero cost today - so a project built from this can later pin a version and pull kernel
   fixes instead of cherry-picking them.

### Non-goals

- Not a CMS, not a product. Domain modules ship as **one reference example** that gets
  deleted or renamed in a real project.
- No Kubernetes. Docker Compose + Traefik on a single host, scalable to a small swarm later.
- No zero-downtime deploys in v1. `deploy*` causes a few seconds of downtime while
  containers restart; that is stated, not hidden. Blue/green behind Traefik is a documented
  later step (§8.3).
- No paid SaaS dependencies required to boot (mail is captured locally in dev; error
  tracking, off-box backup, OpenTelemetry and S3 storage are optional and no-op or local
  when unconfigured).
- **No runtime custom fields / custom entities / query index.** The reference framework's biggest
  architectural bet; right for a no-code-leaning ERP, wrong for a Doctrine boilerplate where
  a migration takes 30 seconds and an agent can write it. Escape hatch: a documented
  `attributes JSONB` column convention with a GIN index. ADR-0013.
- **No hierarchical organizations in v1.** Doubles the scoping surface on day one. The
  tenant filter is written for *N* scope columns so `organization_id` can be added later
  without rewriting it. ADR-0014.
- **No undo/redo.** Writes go through a command bus with before/after snapshots (§6.9),
  which makes undo *possible* later without building it now. ADR-0015.
- **No CRM/catalog/sales/workflow-engine/dashboards.** That is a product; this ships
  `Example` and gets out of the way.

---

## 2. Decisions taken

| Question | Decision | Rationale |
|---|---|---|
| Backend structure | **Modular** - `src/Module/<Name>/…` with auto-discovery | Feature stays in one folder; agents keep it coherent as it grows |
| Manager UI | **Separate Nuxt app** on `manager.${DOMAIN}` | Manager bundle never ships to tenant users; separate auth + firewall |
| Remote deploy | **SSH → git pull → build on server** | Works on any fresh VPS with no registry set up |
| Tenancy (storage) | **Shared DB, `tenant_id` + fail-closed Doctrine filter** | Cheap, one migration set, filter makes leaks structurally hard |
| Tenancy (users) | **`Membership(user, tenant, role)` from day one**; JWT carries the *current* tenant; `POST /auth/switch-tenant` re-issues | Every SaaS needs a user in two tenants within a year; retrofitting a membership table is the painful kind of migration |
| Token transport | **Access token in memory; refresh token in an `httpOnly; Secure; SameSite=Strict` cookie on `api.${DOMAIN}`** | XSS in any dependency cannot exfiltrate a long-lived credential |
| Realm isolation | **`aud` claim (`app` \| `manager`) enforced per firewall** | Same signing key + two providers is *not* isolation; a shared email would cross realms |
| Domain events | **Messenger on the Doctrine transport (transactional outbox)**; Redis for pure job queues; `failed` transport on Doctrine | Events enqueued in the same transaction as the write - no side effects from rolled-back writes, no handlers seeing uncommitted data |
| Frontend modules | **Each `frontend/app/modules/<name>/` is a local Nuxt layer** | Auto-registered pages/components/composables; module removable by deleting a folder |
| Staging | **`APP_ENV=prod` + `APP_STAGE=staging`** | Symfony's `when@prod` config keeps applying; staging behaviour is a flag, not a config fork |
| Project rename | **`make init NAME=…` in Phase 0** | Every later naming choice depends on how renaming works |
| API docs | **`nelmio/api-doc-bundle`, `openapi.json` committed and diffed in CI** | §12.1 relies on it as a machine-readable source of truth for agents |
| Server user | **Provision a `deploy` user; everything after the first connection runs as it** | Root is only used to create `deploy`; `deploy` is in the `docker` group, which is root-equivalent - stated, not hidden |
| Kernel packaging | **`backend/kernel/` = composer package `open-enu/kernel` (`OpenEnu\Kernel\`), `ui-kit/` = npm workspace `@open-enu/ui-kit`, both path-installed** | The namespace stays stable across projects (`make init` never renames it), which is exactly what lets kernel updates flow later. The reference framework's "never fork" model, at the cost of one `composer.json` |
| Writes | **Command bus** (Messenger sync bus): every mutation is a `Command` + handler; audit middleware captures before/after snapshots | Uniform audit for free; undo possible later; controllers become trivially thin |
| Custom fields | **Not adopted** - `attributes JSONB` convention instead | ADR-0013; see non-goals |
| Hierarchical orgs | **Not in v1**; filter designed for N scope columns | ADR-0014; see non-goals |
| Undo/redo | **Not adopted**; command snapshots keep the door open | ADR-0015 |
| Cache | **`TagAwareCacheInterface` on Redis only**, tenant-prefixed keys, `tenant:{id}` tag | Raw `CacheItemPoolInterface` banned in modules by PHPStan; tenant deletion invalidates one tag |
| i18n | **`pl` + `en` shipped**; `@nuxtjs/i18n` in all three apps; `Accept-Language` on the API; validation + email templates translated | Hard-coded UI strings fail lint. Translatable *entity fields* are not adopted |
| Encryption | **Light**: `#[Encrypted]` attribute → Doctrine type, AES-GCM, per-tenant DEK via HKDF(master key, tenant id), `*_hash` columns for lookups | Boots with zero external services; documented upgrade path to a KMS. A Vault-first design is right for enterprise, wrong for a boilerplate |
| Search | **`SearchIndexerInterface` with Postgres `tsvector` as the only built-in driver** | No Meilisearch dependency; pgvector already in the image for a later vector driver |
| `ui-kit/` location | **Top-level folder, stays** | It is an npm package; a package nested under another app's folder is awkward |
| DNS apply scripts | **Cloudflare only in v1**; `zone.txt` + `records.json` for everyone else | Add a provider script the first time a real deploy lands on it |
| Deploy-key rotation | **Manual** (`make deploy-key` with a new filename + delete the old key on GitHub) | Not worth automating before there is a second server |
| Landing content | **Markdown via `@nuxt/content`** | An API-driven landing couples the marketing site to the backend being up |
| Backup destination | **`buildprod` refuses to finish without `BACKUP_REMOTE`; `buildstaging` warns nightly** | Production without an off-box backup must not be one command away |
| Hierarchical organizations | **Wait for demand**; ADR-0014 keeps the N-column filter | No known first project needs workspaces-inside-a-tenant |
| Kernel publishing | **Per-project, later**; the path package preserves the option | Publishing adds release overhead before there is a second consumer |
| Phase 2 | **Split into 2a (module system + guardrails) and 2b (platform contracts)** | Cleaner first milestone; ordering unchanged |

---

## 3. Stack

| Layer | Choice | Version |
|---|---|---|
| API | Symfony (skeleton, no full-stack bundle) | 7.4 LTS, PHP 8.4 |
| ORM | Doctrine ORM + Migrations | 3.x |
| Auth | `lexik/jwt-authentication-bundle` + `gesdinet/jwt-refresh-token-bundle` | 3.x / 2.x |
| API docs | `nelmio/api-doc-bundle` → `openapi.json` | 5.x |
| Async | Symfony Messenger - Doctrine transport (outbox + failed), Redis transport (jobs); Symfony Scheduler | 7.4 |
| Realtime | Mercure hub (SSE) | `dunglas/mercure` **pinned** |
| DB | PostgreSQL (pgvector image - extension available, unused by default) | 16 |
| Cache/queue | Redis | 8 |
| Mail | Symfony Mailer; Mailpit in dev, SMTP/Brevo in prod | - |
| Errors | Sentry SDK in all four apps, **no-op when `SENTRY_DSN` is empty** | - |
| Logs / traces | Monolog JSON to stdout with tenant/user/request-id processor; OpenTelemetry SDK **optional**, off unless `OTEL_EXPORTER_OTLP_ENDPOINT` is set | - |
| Files | Flysystem - local adapter in dev, S3-compatible in prod, signed URLs | 3.x |
| Webhooks | Standard Webhooks signing (`webhook-id`/`-timestamp`/`-signature`), delivered via the jobs queue | - |
| i18n | `symfony/translation` + `@nuxtjs/i18n` | - |
| E2E | Playwright, in `e2e/`, run in `make ci` only | - |
| Backups | `pg_dump` → `age`-encrypted → `rclone` to any S3-compatible bucket | - |
| Frontend | Nuxt / Vue / Pinia / Tailwind / VueUse / lucide | 4 / 3.5 / 3 / 4 |
| Runtime | Node | 22 LTS |
| Proxy | Traefik (mkcert in dev, Let's Encrypt in staging/prod) | **pinned** (v3.x), bumped by Dependabot |
| Quality | PHPStan L8 + custom rules, PHP-CS-Fixer, PHPUnit 11 / ESLint 9, vue-tsc, Vitest, `knip` | - |

**No `:latest` in `compose.prod.yml`.** Every image is pinned to a version tag and
Dependabot's `docker` ecosystem is enabled so bumps are deliberate PRs.

---

## 4. Repository layout

```
open-enu/
├── Makefile                  # the single entry point - dev + remote
├── AGENTS.md                 # agent contract: Always / Ask First / Never / Task Router
├── CLAUDE.md                 # → @AGENTS.md
├── README.md                 # human quickstart
├── .env.example              # docker/stack-level vars (domain, creds, ports)
│
├── package.json              # npm workspaces: ui-kit, frontend, manager, landing, e2e
│
├── backend/                  # Symfony API            → api.${DOMAIN}
│   ├── kernel/               # composer package open-enu/kernel (OpenEnu\Kernel\) - path-installed
│   └── src/                  # App\ - Kernel.php + Module/*
├── frontend/                 # Nuxt tenant app        → app.${DOMAIN}
├── manager/                  # Nuxt platform console  → manager.${DOMAIN}
├── landing/                  # Nuxt marketing site    → ${DOMAIN}
├── ui-kit/                   # npm package @open-enu/ui-kit - Nuxt layer shared by the three apps
├── e2e/                      # Playwright specs for what functional tests can't see (cookies, SSE, banners)
│
├── docker/
│   ├── compose.dev.yml
│   ├── compose.prod.yml
│   ├── compose.override.example.yml
│   ├── edge/{compose.yml,traefik.yml}   # the shared dev proxy, one per Docker host
│   ├── traefik/{traefik.prod.yml,dynamic.prod.yml,acme/}
│   ├── postgres/init/01-init.sql
│   └── certs/                # mkcert output (gitignored)
│
├── scripts/
│   ├── lib/                  # log.sh, require.sh, envgen.sh, secrets.sh, os.sh (Linux/macOS/WSL2)
│   ├── dev/                  # bootstrap.sh, certs.sh, hosts.sh, jwt.sh, sync-deps.sh, init.sh
│   └── remote/               # preflight.sh, deploy-key.sh, provision.sh, deploy.sh, backup.sh
│
├── .ai/                      # what is NOT under platform/ is the project's (ADR-0023)
│   ├── platform/             # ships with the platform; an update replaces it wholesale
│   │   ├── PLAN.md           # this file
│   │   ├── docs/             # long-form guides (module-development, deployment, tenancy…)
│   │   ├── specs/            # {YYYY-MM-DD}-{kebab-title}.md, one per non-trivial feature
│   │   ├── adr/              # architecture decision records
│   │   ├── analysis/         # reviews, audits, gap reports
│   │   ├── skills/           # canonical skills - create-module, add-endpoint, review-change
│   │   └── lessons/          # corrections captured after mistakes
│   ├── adr/ specs/ lessons/ analysis/   # the PROJECT's own - same shape, indexes only here
│   └── inventory.json        # generated: what already exists (§12.1)
│
└── .claude/skills/           # symlinks into .ai/platform/skills/ (Claude Code can't read .ai/ directly)
```

**`ui-kit/` is an addition** to the four folders you named. Three Nuxt apps would otherwise
each carry their own copy of the API client, auth store, SSE composable, toast system and
Tailwind theme. As a Nuxt layer it is written once and `extends`-ed by all three. If you'd
rather have exactly four folders, say so and it collapses into `frontend/layers/core` with
the other two apps referencing it by relative path.

**Skills live in `.ai/platform/skills/`** (canonical, vendor-neutral) with a symlink layer in
`.claude/skills/`. This repo will outlive any single tool.

**`backend/kernel/` is the framework; `backend/src/` is the app.** `make init` renames the
app (`PROJECT_NAME`, containers, DBs, domains) and never the kernel namespace. A project can
therefore later replace the path repository with a versioned `open-enu/kernel` tag and
`composer update` it. Modules import `OpenEnu\Kernel\Contract\*`; nothing in the kernel
imports `App\`.

---

## 5. Domain topology

One base domain drives everything. `DOMAIN=open-enu.local` in dev, `open-enu.com` in prod.

| Host | Serves | dev | staging | prod |
|---|---|---|---|---|
| `${DOMAIN}` | landing | ✓ | ✓ | ✓ |
| `www.${DOMAIN}` | → redirect to apex | ✓ | ✓ | ✓ |
| `app.${DOMAIN}` | frontend (tenant users) | ✓ | ✓ | ✓ |
| `manager.${DOMAIN}` | manager (platform operators) | ✓ | ✓ | ✓ |
| `api.${DOMAIN}` | Symfony API | ✓ | ✓ | ✓ |
| `api.${DOMAIN}/.well-known/mercure` | Mercure SSE hub - on the API host, so its cookie is host-only | ✓ | ✓ | ✓ |
| `traefik.${DOMAIN}` | Traefik dashboard | ✓ | basic-auth | ✗ |
| `mail.${DOMAIN}` | Mailpit UI | ✓ | ✗ | ✗ |

Nothing in the code hard-codes a domain. Every Traefik rule is
`Host(\`app.${DOMAIN}\`)`, every Nuxt runtime config reads `NUXT_PUBLIC_*`, and CORS +
Mercure origins are generated from `DOMAIN`. Changing the domain is one `.env` line.

**CORS is exact-origin, never wildcard:** `https://app.${DOMAIN}` and
`https://manager.${DOMAIN}`, with `Access-Control-Allow-Credentials: true` because auth
rides on cookies (§6.6). A functional test asserts a third origin is refused.

**Cookies across subdomains work without a `Domain=` attribute** for the API: `app.${DOMAIN}`
→ `api.${DOMAIN}` is same-site (site = eTLD+1), so `SameSite=Strict` cookies scoped to
`api.${DOMAIN}` are sent. **No cookie carries a `Domain=` attribute**, Mercure's included: the
hub is served from `api.${DOMAIN}/.well-known/mercure`, the host that sets the cookie.
`Domain=.${DOMAIN}` would also reach every host beneath it - a staging stack at
`stg.${DOMAIN}` would receive production's subscriber token.

**DNS prerequisite for staging/prod:** an `A` record for the apex plus a wildcard
`*.${DOMAIN}` pointing at the server, created before `make buildprod`. The exact record
set, the CAA trap, and the checks that verify all of it are in
[§10 Preflight](#10-preflight--dns-repository-access-ssh-keys).

---

## 6. Backend architecture

### 6.1 Layout

```
backend/
├── kernel/                         # composer package open-enu/kernel - framework, no business logic
│   ├── composer.json
│   └── src/                        # OpenEnu\Kernel\
│       ├── Module/                 # ModuleInterface, ModuleRegistry, discovery pass
│       ├── Http/                   # ApiExceptionListener, ApiResponse, Paginator, Health (shallow + deep), ConflictResponse (409)
│       ├── Doctrine/               # ScopeFilter (fail-closed, N scope columns), UuidType, EncryptedType, VersionedListener
│       ├── Messenger/              # TenantStamp, RequestIdStamp, ScopeMiddleware, OutboxMiddleware, AuditMiddleware
│       ├── Command/                # CommandBusInterface, AbstractCommand, Snapshot
│       ├── Security/               # AudienceListener, ImpersonationGuard, ApiKeyAuthenticator, RateLimits
│       ├── Cache/                  # TenantCache (TagAware, tenant-prefixed), CacheKey
│       ├── Storage/                # StorageInterface (Flysystem), SignedUrl
│       ├── Crypto/                 # Encryptor (AES-GCM), TenantKeyDeriver (HKDF), Hasher
│       ├── Event/                  # DomainEvent base, EventRegistry, ClientBroadcast attribute
│       ├── Progress/               # ProgressReporterInterface
│       ├── Gdpr/                   # GdprSubjectInterface, GdprWalker
│       ├── Search/                 # SearchIndexerInterface, PostgresTsvectorIndexer
│       ├── Setup/                  # TenantSetupInterface, SetupRunner
│       ├── Logging/                # ContextProcessor (tenant/user/request-id), RequestId
│       ├── I18n/                   # LocaleResolver (Accept-Language → user pref → default)
│       ├── Contract/               # TenantScopedInterface, VersionedInterface, ClockInterface, AuditLoggerInterface…
│       ├── Attribute/              # #[Encrypted], #[Unscoped], #[NoTestRequired], #[DeniedUnderImpersonation], #[Flag]
│       ├── Dto/                    # PaginationRequest, ListResponse, ErrorResponse, ConflictBody
│       └── Console/                # app:module:create, app:module:list, app:openapi:export, app:gdpr:*
└── src/                            # App\
    ├── Kernel.php
    └── Module/
        ├── Tenant/
        ├── Identity/
        ├── ApiKey/
        ├── Manager/
        ├── Settings/               # settings + feature flags
        ├── Audit/
        ├── Attachment/
        ├── Progress/
        ├── Webhook/
        ├── Notification/
        └── Example/                # the copy-me reference module (minimal - see §6.7)
```

### 6.2 Module anatomy

Every module is the same shape, so an agent that has read one has read them all:

```
Module/Example/
├── MODULE.md               # agent-facing: what it owns, its contracts, its events
├── module.yaml             # name, description, depends: [Tenant, Identity], enabled
├── Contract/               # PUBLIC surface - the only thing other modules may import
│   └── ProjectReaderInterface.php
├── Controller/Api/         # thin: validate → delegate → serialize. No logic.
├── Dto/                    # Request DTOs (validated) + Response DTOs (serialized)
├── Entity/                 # Doctrine entities, tenant-scoped where relevant
├── Repository/             # query objects; the ONLY place raw SQL is allowed (with #[Unscoped])
├── Service/                # the business logic; constructor injection only
├── Event/ + Listener/      # domain events (via outbox); the cross-module integration point
├── Message/ + Handler/     # Messenger async work
├── Security/Voter/         # authorization decisions
├── Acl/permissions.php     # declared permission strings → role defaults
├── Migrations/             # module-owned migrations
├── Fixtures/               # demo data for `make seed` - fixed UUIDs, fixed clock
├── Tests/{Unit,Functional}/
└── openapi/                # generated fragment, committed
```

### 6.3 Discovery - how a module becomes live with zero wiring

- **Services**: `config/services.yaml` registers `../src/Module/*/{Controller,Service,Repository,Listener,Handler,Voter,Command}` with autowire + autoconfigure.
- **Routes**: attribute route loading over `../src/Module/*/Controller/`.
- **Entities**: a compiler pass scans `src/Module/*/Entity` and registers one Doctrine mapping per module (`App\Module\<Name>\Entity` → that dir).
- **Migrations**: `doctrine_migrations.migrations_paths` built from the same scan, so each module owns its migration history. Versions are timestamps, so ordering across modules is global and deterministic.
- **Permissions**: `ModuleRegistry` aggregates every `Acl/permissions.php` into the role hierarchy at boot.
- **Fixtures**: auto-loaded from `Module/*/Fixtures`.
- **OpenAPI**: `app:openapi:export` regenerates `backend/openapi.json` from attributes; CI fails if the committed file is stale.

Result: create the folder, run `make module NAME=Billing`, and the module is routed,
mapped, migratable, seedable and documented without editing a single central file.

### 6.4 The boundary rules (enforced, not just documented)

| Rule | How it is enforced |
|---|---|
| No cross-module ORM associations or entity imports | Doctrine mapping per module + a PHPStan rule: only another module's `Contract\` and `OpenEnu\Kernel\*` may be imported across module boundaries |
| **No cross-module DB foreign keys.** `tenant_id` and any other cross-module reference is an indexed UUID column; integrity is application-level, cleanup is event-driven | Migration linter: a `FOREIGN KEY` whose target table belongs to another module fails `make arch` |
| Cross-module writes go through events/messages, dispatched via the outbox | PHPStan: `EventDispatcherInterface` and `MessageBusInterface` are injectable only in `Service/` and `Handler/`; the outbox middleware is on by default |
| Controllers stay thin | PHPStan: no `EntityManager` in controllers, max method length |
| No raw SQL outside `Repository/`, and never without `#[Unscoped(reason: '…')]` | PHPStan: `Connection::executeQuery`/`createNativeQuery` allowed only in `Repository/` methods carrying the attribute; the attribute is greppable for review |
| Every tenant entity is scoped | `TenantScopedInterface` + a test that asserts every entity implementing it is covered by the filter |
| Every write is a command | PHPStan: `flush()` is callable only from `Handler/` and kernel; controllers dispatch commands |
| Cache only through `TenantCache` | PHPStan: `CacheItemPoolInterface` / `CacheInterface` injectable only in kernel |
| Files only through `StorageInterface` | PHPStan: `file_put_contents`, `fopen('…w')`, `LocalFilesystemAdapter` banned outside kernel |
| No hard-coded user-facing strings | PHP: exception messages / validation / email via translation keys (custom PHPStan rule on `Response` bodies); Vue: `@intlify/eslint-plugin-vue-i18n` no-raw-text |
| Every user-editable entity is versioned | `VersionedInterface` required on any entity with a `PUT`/`PATCH` route; arch test walks the router |

`make arch` fails the build on any of these. That is what keeps an agent honest across a
long vibecoding session.

### 6.5 Multi-tenancy

**Model.**

- `Tenant` (uuid, slug, name, status, plan, created_at) - owned by the `Tenant` module.
- `Membership` (user_id, tenant_id, role, status, created_at) - a user belongs to *n*
  tenants with a role in each. Owned by `Identity`; references `Tenant` by UUID, no FK.
- The JWT carries `tid` (**current** tenant) and `role` for that membership.
  `POST /auth/switch-tenant {tenantId}` verifies the membership and re-issues both tokens.
  The frontend shows a tenant switcher only when the user has more than one membership.

**Filter - fail closed.**

- `TenantContext` (request-scoped, `ResetInterface`) is populated from the JWT by a
  listener, or from the `TenantStamp` by the Messenger middleware, or explicitly by CLI
  commands that take `--tenant`.
- A Doctrine SQL filter appends `tenant_id = :tenant_id` to every query on a scoped entity.
  **When `TenantContext` is empty the filter emits `1 = 0`** - an unauthenticated route, an
  un-stamped message, or a forgotten CLI flag returns nothing rather than everything.
- The filter is `ScopeFilter`, written for **N scope columns** (`tenant_id` today). Adding
  `organization_id` later is a second entry in its column map plus a claim in the JWT, not
  a rewrite. That is the whole of ADR-0014's "door left open".
- Disabling the filter is an explicit, auditable call: `TenantContext::runUnscoped(callable,
  reason)`. Used by the Manager module and by provisioning; every call is logged with its
  reason.

**The three known bypasses, and what closes each.**

| Bypass | Closure |
|---|---|
| Native SQL / DBAL skips the filter | Allowed only in `Repository/` with `#[Unscoped]` (§6.4); a scoped repository must add the `tenant_id` predicate by hand and a test asserts it |
| `getReference()` issues no query | Banned in modules via PHPStan; use `find()` |
| **Identity map**: a row loaded inside `runUnscoped()` is later returned by a scoped `find()` from memory, without SQL | `runUnscoped()` calls `EntityManager::clear()` on exit - always, including on exception |

**Writes.** `TenantOwnedVoter` refuses any write whose payload names a tenant other than the
current one; the entity's `tenant_id` is set from context, never from the request body.

**Async.** Every message dispatched inside a request gets a `TenantStamp` automatically;
the worker-side middleware re-establishes `TenantContext` before the handler runs and
resets it after. A message without a stamp hits the fail-closed filter and its handler
gets nothing - loud, not silent.

**Realtime.** Mercure topics embed the tenant: `/tenants/{tid}/projects/{id}`. The
subscriber JWT the API issues restricts `mercure.subscribe` to `["/tenants/{tid}/**"]` -
never `["*"]`. Because `EventSource` cannot set headers, that JWT travels as a
`mercureAuthorization` cookie: `httpOnly; Secure; SameSite=Strict; Path=/.well-known/mercure`,
host-only on `api.${DOMAIN}` (the hub's host too). A functional test subscribes as tenant A and asserts a publish
to tenant B's topic is not delivered.

### 6.6 Security

**Two identity realms, isolated by audience - not just by firewall order.**

| Realm | Provider | Firewall | `aud` |
|---|---|---|---|
| Tenant users | `Identity\Entity\User` | `^/api` (stateless JWT) | `app` |
| Platform operators | `Manager\Entity\PlatformManager` | `^/api/manager` (declared first) | `manager` |

Two firewalls sharing one signing key are *not* isolation: a tenant token presented to
`/api/manager` passes signature verification and is then looked up by email in the
manager provider - a shared email crosses realms. So every token carries `aud`, and
`OpenEnu\Kernel\Security\AudienceListener` (on `lexik_jwt_authentication.on_jwt_authenticated`)
rejects any token whose `aud` does not match the firewall that received it. Phase 4's
acceptance test is specifically *"a tenant token for a user whose email equals a manager's
email is rejected on `/api/manager`"*.

**Three principals, three audiences.** Besides the two realms above, machine clients
authenticate with an **API key** (`Authorization: Bearer sk_live_…`): `aud: api_key`,
hashed at rest (SHA-256, prefix kept in clear for lookup), created per tenant with a
**permission subset** chosen from the tenant's `Acl` catalogue, revocable, rotatable, with
`last_used_at`. `ApiKeyAuthenticator` sits on the `^/api` firewall before the JWT
authenticator; a key never gets a refresh token and cannot switch tenant. Rate limit:
1,000 / min per key. The `Example` module's functional suite runs its list endpoint once as
a user and once as a key.

**Token transport.**

| Token | Lives in | Lifetime |
|---|---|---|
| Access (JWT) | Browser memory only - never `localStorage`, never a cookie | 15 min |
| Refresh | `httpOnly; Secure; SameSite=Strict` cookie on `api.${DOMAIN}`; manager's is additionally `Path=/api/manager` | 30 days, DB-backed, revocable, rotated on use |
| Mercure subscriber | `mercureAuthorization` cookie, see §6.5 | = access token |

`useApi` (§7.1) keeps the access token in a closure, refreshes on 401 via the cookie, and
retries once. A page reload costs one refresh call, not a login.

**Impersonation - the one legitimate realm crossing, so it is fully specified.**

- `POST /api/manager/tenants/{id}/users/{uid}/impersonate` (manager realm) issues an
  **`app`-audience** token with: `act: {sub: <managerId>, realm: manager}`, `tid`, `exp` ≤
  15 minutes, and `imp: true`. It is **not refreshable** - no refresh cookie is set.
- `OpenEnu\Kernel\Security\ImpersonationGuard` denies every route tagged `#[DeniedUnderImpersonation]` -
  tenant deletion, billing changes, member removal, credential changes - and the
  `Example` module shows the tag in use.
- Every request under `imp: true` writes an `Audit` entry with both identities.
- `frontend` renders a persistent banner ("Viewing as … - managed by …") whenever the token
  carries `imp`, with a one-click exit that discards the token.

**Roles and permissions.** `Acl/permissions.php` in each module declares permission strings
(`example.project.create`). Roles are named bundles of permissions *per membership*:
`owner > admin > member` inside a tenant, `platform_manager` in the manager realm. Voters
check permissions, never role names, so a project can add roles without touching modules.

**Registration and secrets hygiene.**

- Self-registration creates a user + tenant in `pending` status; the tenant activates only
  after **email verification**. Unverified tenants are purged after 7 days by a scheduled
  job. Without this, an open signup form is a tenant-spam endpoint.
- Password-reset, invitation and verification tokens are **single-use, stored hashed
  (SHA-256), expiring** (1 h / 7 d / 24 h). The raw token exists only in the email.
- Passwords: `auto` hasher (Argon2id where available), minimum length enforced server-side.

**Rate limiting** (Symfony RateLimiter, Redis-backed, per IP *and* per identifier):

| Surface | Limit | Note |
|---|---|---|
| `/api/manager/login` | 5 / 15 min | the crown jewel; the **strictest** limiter |
| `/api/auth/login` | 10 / 15 min | |
| `/api/auth/register`, `/password/forgot`, `/invitations/*/accept` | 5 / h | enumeration + spam |
| `/api/auth/refresh` | 60 / h | |
| Mercure subscriber-token issuance | 30 / min | |

**Field-level encryption, the light version.** `#[Encrypted]` on an entity property makes
its Doctrine type `encrypted_string`: AES-256-GCM, random 12-byte IV, payload
`v1:base64(iv):base64(ct):base64(tag)`. The key is a **per-tenant DEK derived by HKDF** from
`APP_ENCRYPTION_KEY` (in `.env`, generated by `envgen`, backed up with the secrets bundle) and
the tenant UUID - so no key material is stored per tenant and tenant deletion is
cryptographic (rotate the salt). Lookups on encrypted fields use a sibling `*_hash`
column (SHA-256 of the normalized value): `User.email` is encrypted, login queries
`email_hash`. A `make encryption:rotate` command re-encrypts under a new master key in
batches. The documented upgrade path is a `KeyProviderInterface` implementation backed by a
KMS; the interface exists from Phase 3 with one implementation.

**GDPR.** `GdprSubjectInterface` in the kernel: `exportFor(userId): iterable<array>` and
`eraseFor(userId): void` (erase = delete or anonymize, module's call). Every module owning
user-linked data implements it; `app:gdpr:export <userId>` walks all modules into one JSON
bundle, `app:gdpr:erase <userId>` walks them in reverse dependency order, both write an
audit entry. The arch test fails on any entity with a `user_id` column whose module lacks
the interface.

**Headers.** Traefik adds HSTS, `X-Content-Type-Options`, `Referrer-Policy`, `Permissions-Policy`.
CSP is app-level: each Nuxt app ships a strict default (`default-src 'self'`, nonce'd
scripts, `connect-src` limited to `api.`, which also serves Mercure) that a project loosens deliberately.

### 6.7 Shipped modules

| Module | Contents |
|---|---|
| **Tenant** | Tenant entity, `TenantContext`, fail-closed `ScopeFilter`, provisioning service running every module's `TenantSetupInterface` in dependency order, tenant CRUD contract, cascade-cleanup on `TenantDeleted` (incl. `tenant:{id}` cache tag, storage prefix, DEK salt) |
| **Identity** | User (encrypted email + hash), Membership, registration + email verification, login, refresh (rotating), logout, switch-tenant, password reset, invitations, profile with avatar (via Attachment), locale preference. **The full-fat reference** - see below |
| **ApiKey** | Per-tenant keys with permission subsets, create/list/revoke/rotate, `last_used_at`, settings page in `frontend` |
| **Manager** | PlatformManager, manager login, tenant list/detail/suspend, **impersonation per §6.6**, platform metrics, audit viewer, scheduler status view (last run / next run / failures per task) |
| **Settings** | Typed settings + feature flags (`bool\|string\|number\|json`), global default → tenant override resolution, tag-cached, audited; `#[Flag('billing.v2')]` route/handler guard; editable in `manager`, tenant-level overrides editable by tenant owners where the flag allows it |
| **Audit** | `AuditLoggerInterface` + the command-bus audit middleware: who, which tenant, which command, before/after snapshot, request id, under impersonation or not. Append-only table. Queryable from the manager console |
| **Attachment** | `StorageInterface` (Flysystem local/S3), tenant-prefixed paths, MIME + size validation, signed download URLs (5 min), `AttachmentInterface` any module attaches to, orphan sweep job |
| **Progress** | `ProgressJob` (id, tenant, kind, total, done, status, result), `ProgressReporterInterface` for handlers, publishes to `/tenants/{tid}/progress/{id}`; the "start a long job, watch it" pattern |
| **Webhook** | Outbound only: per-tenant endpoints subscribed to event names, Standard Webhooks signing, delivery via jobs queue with exponential retry (5 attempts), delivery log with response codes, manual redeliver; endpoint secret encrypted. Inbound = `InboundAdapterInterface` only, no built-in providers |
| **Notification** | Notification **type registry** (each module declares its types + default channels), `RealtimePublisherInterface` (Mercure), `MailerInterface` (Messenger + Twig, translated), per-user in-app feed with read state |
| **Example** | **Minimal by design**: one entity (`Project`) that is versioned, tenant-scoped, has one encrypted field, one attachment and one `attributes` JSONB; one list + one create + one update endpoint (update proves the 409 path); one command + handler; one voter; one domain event marked `#[ClientBroadcast]` and subscribed by one webhook; one progress-reporting handler; one flag-guarded route; one `TenantSetupInterface` seeding a default; one fixture; one unit + one functional test (run as user **and** as API key); one frontend page with i18n keys; one Playwright spec. ≤ 800 LOC including tests - every cross-cutting concern is an attribute or a one-liner, which is the point |

**Two-tier reference.** `Example` is what an agent reads on *every* task, so it must stay
cheap to read and shows exactly one of everything - including one use of each platform
service, because those uses are one-liners (`#[Encrypted]`, `implements VersionedInterface`,
`#[ClientBroadcast]`, `#[Flag('…')]`). `Identity` is the reference for the harder cases -
pagination with filters, multi-step flows, encrypted lookups - and `AGENTS.md`'s Task
Router points there for those. v1 tried to make one module be both, and its ~600 LOC budget
was fiction; v3's 800 is measured, not hoped.

### 6.8 Events and async - the outbox

Cross-module writes happen through domain events, and *when* they fire matters:

- Domain events are Messenger messages dispatched to the **Doctrine transport**. The
  dispatch is written to the `messenger_messages` table in the **same transaction** as the
  entity change. A rollback rolls the event back with it; a commit guarantees delivery.
- A worker consumes the Doctrine transport and dispatches to handlers. Handlers that fan
  out heavy work (PDF, email, third-party calls) push **jobs** to the Redis transport.
- The `failed` transport is Doctrine, so a dead letter is a row you can inspect and retry
  with `messenger:failed:retry`, not a Redis blob you can't read.
- `DispatchAfterCurrentBusMiddleware` is enabled, so a handler that dispatches further
  messages doesn't interleave with its own transaction.

The `Example` module demonstrates the whole chain: `ProjectCreated` (outbox) →
`NotifyProjectCreatedHandler` → Mercure publish via `RealtimePublisherInterface`.

### 6.9 Platform services - what the kernel gives every module

Each is one contract, one default implementation, one PHPStan rule that forces its use, and
one line in `Example`. None requires an external service to boot.

| Service | Contract | Default | Forced by | Shown in `Example` as |
|---|---|---|---|---|
| Command bus | `CommandBusInterface`, `AbstractCommand` | Messenger sync bus + `AuditMiddleware` (snapshots via `prepare`/`captureAfter` on the handler) | `flush()` banned outside handlers | `CreateProject` + handler |
| Tag cache | `TenantCache` (`TagAwareCacheInterface`) | Redis; keys `t:{tid}:…`; tags `tenant:{tid}`, `module:{name}` | raw cache pools banned | cached project count |
| Optimistic locking | `VersionedInterface` (`version` int, `updatedAt`) | `VersionedListener` bumps on flush; `If-Match: "v"` or `updatedAt` in body → `409 ConflictBody{current, yours}` | required on any entity with PUT/PATCH | `Project` update |
| Storage | `StorageInterface` | Flysystem local (`var/storage`) in dev, S3 in prod, path `tenants/{tid}/{module}/…`, signed URLs | raw FS calls banned | `Project.cover` attachment |
| Encryption | `#[Encrypted]`, `KeyProviderInterface` | AES-GCM, HKDF per-tenant DEK, `*_hash` sibling | - | `Project.internalNote` |
| Typed events + browser bridge | `DomainEvent`, `#[ClientBroadcast]` | outbox → handler; `#[ClientBroadcast]` events are also published to `/tenants/{tid}/events` and consumed by `useAppEvent()` | event classes must live in `Event/` | `ProjectCreated` |
| Progress | `ProgressReporterInterface` | `ProgressJob` + Mercure topic; `useProgress()` in ui-kit | - | `ArchiveProjectsHandler` reports 0→n |
| Feature flags + settings | `FlagsInterface`, `SettingsInterface`, `#[Flag]` | global → tenant resolution, tag-cached | - | `#[Flag('example.archive')]` on the archive route |
| Webhooks | (module) `WebhookPublisher` subscribes to any `DomainEvent` by name | Standard Webhooks, jobs queue, retry | - | `example.project.created` subscribable |
| Logging + correlation | `RequestId`, `ContextProcessor` | Monolog JSON: `request_id`, `tenant_id`, `user_id`, `command`, `route`; `RequestIdStamp` carries it into workers; `X-Request-Id` echoed in responses | - | visible in every log line |
| Tenant setup | `TenantSetupInterface` (`onTenantCreated`, `seedDefaults`) | `SetupRunner` in dependency order, idempotent | - | seeds a "Getting started" project |
| GDPR | `GdprSubjectInterface` | `app:gdpr:export` / `app:gdpr:erase` | any `user_id` column without it fails arch | exports/erases the user's projects |
| Search | `SearchIndexerInterface` | Postgres `tsvector` column + GIN, `search.php` per module declaring indexed fields | - | `Project.name`, `Project.description` |
| i18n | `LocaleResolver`, translation keys | `pl`, `en`; `Accept-Language` → user pref → tenant default → `en` | raw strings banned | all messages via keys |
| API keys | (module) `ApiKeyAuthenticator` | see §6.6 | - | functional test runs as key |

**Read this table before adding any infrastructure to a module.** The Task Router's first
row points here. If a module needs something not in this table, that is an `Ask First`.

### 6.10 Extension surfaces - where to hook in without modifying

"Modify nothing, extend everything" is a slogan until the surfaces are listed. These are
ours, in Symfony/Nuxt terms; `.ai/platform/docs/extension-surfaces.md` carries the worked example
for each and `make module` links to it.

| To change… | Hook | Mechanism |
|---|---|---|
| Behaviour of a kernel or module service | Decorate it | `#[AsDecorator(OriginalService::class)]` - the original stays untouched and testable |
| What happens after a write | Subscribe to its event | `#[AsEventListener]` / Messenger handler on the module's `DomainEvent`; multiple handlers, ordered by priority |
| Validation of a command | Add a middleware or a validator | Messenger middleware on the command bus, or a `#[AsTaggedItem('command.validator')]` service |
| A response body from another module | Enrich it | `ResponseEnricherInterface` tagged service keyed by DTO class - appends fields, never removes |
| Access to a route | Add a voter | Voters compose; any `DENIED` wins; new permission strings go in your own `Acl/permissions.php` |
| Which modules exist | `module.yaml` `enabled: false` | discovery skips it - routes, entities, migrations, permissions all disappear |
| A UI component from `ui-kit` | Override by path | Nuxt layers: same path in your app layer wins; the original is importable as `#ui-kit/…` for wrapping |
| A page's layout regions | Fill a named slot | `<Injection spot="project.detail.sidebar">` in ui-kit layouts; any module layer registers into a spot |
| Navigation | Contribute menu items | `defineNavigation()` in the module layer; merged and permission-filtered |
| A settings page | Register a settings tab | `defineSettingsTab()` in the module layer |
| Notification rendering | Register a renderer per type | `notifications.ts` in the module layer |
| The API surface for machines | Declare permissions | API keys can only be granted permissions your `Acl` declares |
| Scheduled work | Add a `#[AsSchedule]`-attributed task in `Scheduler/` | discovered; visible in the manager's scheduler view |

What is **not** extendable, on purpose: the tenant filter, the audience check, the audit
middleware, the outbox. Those are the guarantees, and a guarantee you can decorate away is
not one.

---

## 7. Frontend architecture

### 7.1 `ui-kit/` - the shared Nuxt layer

```
ui-kit/
├── nuxt.config.ts            # exported as a layer
├── app/
│   ├── components/ui/        # Button, Input, Select, Modal, Table, Toast, EmptyState, ImpersonationBanner…
│   ├── composables/
│   │   ├── useApi.ts         # fetch wrapper: base URL, in-memory access token, refresh-on-401 via cookie, error shape, 409 → ConflictError
│   │   ├── useAuth.ts        # login/logout/refresh/switch-tenant, generic over the auth endpoint
│   │   ├── useMercure.ts     # SSE subscribe with auto-reconnect, tenant-scoped topics
│   │   ├── useAppEvent.ts    # subscribe to #[ClientBroadcast] domain events by name
│   │   ├── useProgress.ts    # follow a ProgressJob id → {done,total,status,result}
│   │   ├── useFlags.ts       # feature flags + settings, resolved server-side once per session
│   │   ├── useUpload.ts      # attachment upload with progress, signed-URL download
│   │   ├── useConflict.ts    # the 409 conflict bar: reload / overwrite / diff
│   │   └── useToast.ts
│   ├── i18n/                 # pl.json, en.json for ui-kit strings; apps add their own
│   ├── stores/               # auth (memory only), ui
│   └── assets/css/           # Tailwind 4 theme + design tokens
└── types/                    # generated from backend/openapi.json - never hand-written
```

`useApi` is the single choke point for HTTP: `credentials: 'include'`, 401 → silent refresh
→ retry once → redirect to login; 409 → typed `ConflictError` that `useConflict` renders.
No component ever calls `$fetch` directly (ESLint-enforced). No component contains a raw
user-facing string (`@intlify/eslint-plugin-vue-i18n`, enforced); `pl` and `en` ship, the
locale follows the user's preference from the API.
API types are **generated** from `openapi.json` (`make types`), so a backend DTO change is
a frontend type error, not a runtime surprise.

### 7.2 `frontend/` - tenant app

```
frontend/
├── nuxt.config.ts            # extends: ['../ui-kit', './app/modules/*']
└── app/
    ├── modules/<name>/       # ONE LOCAL NUXT LAYER PER MODULE
    │   ├── nuxt.config.ts    # the layer manifest
    │   ├── navigation.ts     # defineNavigation() - menu items, permission-filtered
    │   ├── i18n/{pl,en}.json
    │   ├── pages/            # auto-registered by Nuxt - no re-export shims
    │   ├── components/
    │   ├── composables/
    │   ├── stores/
    │   └── types/
    ├── layouts/{default,auth,blank}.vue
    └── middleware/{auth,guest,permission}.ts
```

Each module is a Nuxt layer, so its pages, components and composables register themselves,
and deleting the folder removes the feature with no dangling imports. Frontend module names
match backend module names one-for-one: "add invoicing" → `backend/src/Module/Invoicing/`
+ `frontend/app/modules/invoicing/`, and the symmetry tells the agent where everything goes.

### 7.3 `manager/` - platform console

Same layer, same conventions, different auth endpoint (`/api/manager/login`), `manager`
audience, refresh cookie path-scoped to `/api/manager`, permission guard for
`platform_manager`. Separate container and separate bundle. Includes the Audit log viewer
and the impersonation entry point.

### 7.4 `landing/` - marketing site

Nuxt + `@nuxt/content` for markdown pages (decided - no API-backed content), SSG-friendly,
no auth, no API dependency, so the marketing site stays up when the backend does not.
Ships a real hero/features/pricing/contact skeleton so a new project has something to edit
rather than something to build.

---

## 8. Docker

### 8.1 Dev (`docker/compose.dev.yml`)

Bind-mounted source, hot reload everywhere.

| Service | Notes |
|---|---|
| *(edge)* | **No Traefik in the project.** `enu-edge` (`docker/edge/`) is one Traefik per Docker host, shared by every project on it; `make edge` starts it, registers the project's mkcert cert and `traefik.${DOMAIN}` dashboard, and `make up` attaches it to `${PROJECT_SLUG}-net` with the project's hostnames as aliases. Routers are named `${PROJECT_SLUG}-<service>` |
| `postgres` | pgvector/pg16, healthcheck, port 5432 exposed for TablePlus |
| `redis` | password-protected, appendonly |
| `mercure` | anonymous subscribers allowed in dev only |
| `api` | php-fpm + nginx via supervisord, xdebug available behind a build arg |
| `worker-events` | `messenger:consume outbox` - the Doctrine transport |
| `worker-jobs` | `messenger:consume jobs` - Redis; `stop_grace_period` high so a restart never wedges an in-flight job |
| `scheduler` | `messenger:consume scheduler` |
| `frontend` / `manager` / `landing` | `nuxt dev`, anonymous `node_modules` volume |
| `mailpit` | catches all outbound mail, UI on `mail.${DOMAIN}` |

**WSL2 is a first-class dev host** (it is the machine this plan was written on). `scripts/lib/os.sh`
detects Linux / macOS / WSL2, and on WSL2:

- `hosts` writes the entries to `C:\Windows\System32\drivers\etc\hosts` via an elevated
  PowerShell call (the browser is on Windows; the Linux hosts file is irrelevant to it).
- `trust-certs` installs the mkcert CA into the **Windows** certificate store
  (`mkcert.exe -install` through interop), not only the Linux one.
- `check-tools` verifies `fs.inotify.max_user_watches` and prints the fix; Nuxt hot reload
  dies silently without it (both reference projects hit this).

### 8.2 Prod (`docker/compose.prod.yml`)

- Multi-stage builds: composer `--no-dev --classmap-authoritative` + opcache preload;
  Nuxt built to `.output` and served by `node .output/server/index.mjs`.
- No source bind-mounts. No exposed DB port. No dashboard, no Mailpit.
- Every image pinned to a version tag (§3).
- Traefik with Let's Encrypt HTTP-01, HTTP→HTTPS redirect, security headers, rate-limit
  middleware in front of the API's own limiters as a coarse first layer.
- Resource limits and `restart: unless-stopped` on everything.
- Log rotation configured on the Docker daemon by the provisioning script.
- `/health` (shallow: process up - polled by Traefik) and `/health/deep` (DB, Redis,
  Mercure, outbox lag - polled by the deploy gate). Both unauthenticated, both rate-limited.

**Staging** = the prod compose file with `APP_ENV=prod` and `APP_STAGE=staging`.
`APP_STAGE` drives: `X-Robots-Tag: noindex`, demo seed allowed, `LE_STAGING` optional,
Sentry environment tag. Symfony config never branches on it.

### 8.3 Deploy semantics

`deploy*` is **not zero-downtime**: `docker compose up -d` recreates changed containers,
and a single-replica service is unavailable for the seconds that takes. This is stated in
the target's `## help` line. The documented path to zero-downtime later is two replicas of
`api` and `frontend` behind Traefik with `--scale` and health-gated rotation; it is
deliberately not in v1.

---

## 9. Makefile - the interface

### 9.1 The build commands

```bash
make init NAME=myproject                      # once, right after cloning the boilerplate
make builddev
make buildstaging HOST=deploy@stg.example.com DOMAIN=stg.open-enu.com REPO=acme/myproject
make buildprod    HOST=deploy@1.2.3.4         DOMAIN=open-enu.com     REPO=acme/myproject
```

**`init`** rewrites everything named after the boilerplate: PHP namespace prefix (kept as
`App\` - only the vendor/package names change), `PROJECT_NAME`, compose project + container
names, DB/user names, default `DOMAIN`, Nuxt app titles, `AGENTS.md` references. It is
templated (tokens in a manifest), not `sed` across the tree, and it is idempotent: running
it twice, or on an already-initialized project, is a no-op with a report. It removes itself
from `help` once run.

**`builddev`** (all steps idempotent, safe to re-run):
`check-tools` (incl. WSL2 detection) → `env` → `certs` (mkcert wildcard) → `hosts`
(Linux/macOS/Windows-from-WSL, sudo/elevation prompted once) → `trust-certs` (Linux +
Windows store on WSL2) → `up --build` → `wait-api` → `jwt` (generate keypair if absent) →
`composer install` → `npm ci` ×3 → `migrate` → `seed` → `types` (from `openapi.json`) →
`sync-deps` → print the URL table.

**`buildstaging` / `buildprod`** → `scripts/remote/provision.sh` over SSH:

1. **Preflight** - the full DNS / repo-access / SSH gate of §10, run before anything is
   touched. Ten seconds, read-only, exits non-zero with the exact records or commands
   that are missing.
2. On the server (as root, first and only time): install Docker + compose plugin + git if
   missing (Debian/Ubuntu detected; others fail with a clear message rather than guessing).
3. **Create `deploy` - in lockout-safe order:** create user → add to `docker` group →
   install the operator's public key in `~deploy/.ssh/authorized_keys` → **open a second
   SSH connection as `deploy` and verify it works** → only then disable password auth and
   root login in `sshd_config` and reload. If the verification step fails, `sshd_config`
   is never touched and the command stops with the reason. Then ufw (22/80/443), Docker log
   rotation, swapfile if RAM < 2 GB. Everything from here runs as `deploy`.
4. Sync code: `git clone`/`git pull` into `/opt/<project>` (branch selectable via `REF=`),
   using the read-only deploy key installed by `make deploy-key` (§10.3).
   *Fallback:* `SYNC=rsync` pushes the local working tree instead - needed on day one when
   the new project has no git remote yet. Both paths documented.
5. **Generate `.env` if absent, never overwrite.** `chmod 600`, owned by `deploy`. Secrets
   (`APP_SECRET`, `DB_PASSWORD`, `REDIS_PASSWORD`, `MERCURE_JWT_SECRET`, `JWT_PASSPHRASE`,
   `BACKUP_AGE_KEY`) come from `openssl rand` / `age-keygen`. Re-running reads the existing
   file and preserves every value - this is the property that makes the command safe to
   run against a live server.
6. Generate JWT keypair if absent; `chmod 600`.
7. `docker compose -f compose.prod.yml build && up -d --remove-orphans`.
8. **Backup before migrate**: `pg_dump` (skipped on first run when the DB is empty) → then
   `doctrine:migrations:migrate --no-interaction`, `cache:warmup`.
9. Health-gate: poll `https://api.${DOMAIN}/health/deep` until green or fail the command.
10. Install a systemd unit so the stack comes back after a reboot, and the nightly backup
    timer (§9.3).
11. **Backup gate** (prod only): `BACKUP_REMOTE` must be set and reachable (`rclone lsd`),
    otherwise exit non-zero here - see §9.3.
12. Print the live URL table, and the `age` public key so the operator can store the
    private half somewhere that is not this server.

**`deploystaging` / `deployprod`** are the fast path for subsequent releases:
`make ci` locally (refused if red) → pull → build changed images → backup → migrate →
restart (§8.3) → deep health check.

### 9.2 Full target list

| Group | Targets |
|---|---|
| Setup | `init NAME=…` |
| Build | `builddev` `buildstaging` `buildprod` `deploystaging` `deployprod` |
| Preflight | `preflight` `dns-check` `dns-apply` `repo-check` `server-check` `deploy-key` |
| Lifecycle | `up` `down` `stop` `restart` `rebuild` `status` `ps` |
| Logs | `logs` `logs-api` `logs-ui` `logs-manager` `logs-landing` `logs-workers` |
| Shells | `shell` `shell-ui` `psql` `redis` |
| Data | `migrate` `diff` `seed` `fixtures` `db-dump` `db-restore` `tunnel` `pullproddata` |
| Quality | **`check`** (fast loop, < 60 s) · `ci` (full gate) · `test` `test-unit` `test-functional` `test-ui` **`e2e`** `lint` `lint-fix` `stan` `typecheck` `types` `i18n-check` |
| Platform | `gdpr-export USER=…` `gdpr-erase USER=…` `encryption-rotate` `apikey NAME=… TENANT=…` `flags` `storage-sweep` |
| Agent-readiness | `arch` `docs:check` `agents-budget` `inventory` `module:list` `module:check` |
| Scaffolding | `module NAME=Billing [--no-frontend]` (backend + frontend layer + spec + Task Router row) |
| Ops | `backup` `backup-verify` `restore` `certs-renew` `clean` `clean-all` |
| Meta | `help` (default target - prints the grouped, self-documenting list) |

Every target is annotated with `## comment` and `help` parses them, so the list can never
drift from the implementation.

### 9.3 Backups that are actually backups

A `pg_dump` on the same disk as the database is a hope, not a backup. The nightly timer:

1. `pg_dump -Fc` → `age --encrypt -r $BACKUP_AGE_PUBKEY` → `rclone copy` to
   `$BACKUP_REMOTE` (any S3-compatible bucket: Hetzner Object Storage, Backblaze B2, R2, S3).
2. Retention: 7 daily, 4 weekly, 3 monthly, enforced remotely.
3. Also uploads the `.env`, JWT keys and ACME storage as a separate encrypted bundle, so a
   dead server is restorable to a new one with `make restore` + the `age` private key.
4. If `BACKUP_REMOTE` is unset: **`buildprod` refuses to complete** (it stops after the
   health gate with the exact variable to set and re-run instructions - the stack is up,
   the command is not "done"); `buildstaging` finishes, runs the local dump only, and
   **logs a warning every night** until it is configured.

`make backup-verify` pulls the latest remote dump, restores it into a scratch Postgres
container, and runs `doctrine:schema:validate` + a row-count sanity check. Phase 8's
acceptance criterion includes this passing.

---

## 10. Preflight - DNS, repository access, SSH keys

Every remote target refuses to touch the server until these pass. The point of `buildprod`
is that it is one command; the way it *stays* one command is by failing in the first ten
seconds with an actionable message, instead of half-provisioning a box and dying at ACME.

```bash
make preflight HOST=deploy@203.0.113.42 DOMAIN=open-enu.com REPO=acme/open-enu
```

Read-only, changes nothing, exits non-zero on the first hard failure and prints every
soft warning. `buildstaging` and `buildprod` run it as step 0. `SKIP_PREFLIGHT=1` exists
and is documented as a footgun.

### 10.1 DNS records that must exist

Target IP = the IP in `HOST`, or the resolved A record of its hostname.

| Record | Type | Must point at | Severity |
|---|---|---|---|
| `${DOMAIN}` | A / AAAA | target IP | **hard fail** |
| `*.${DOMAIN}` | A | target IP | hard fail - *unless* every subdomain below exists individually |
| `www` `app` `manager` `api` `mercure` | A / CNAME | target IP | hard fail if there is no wildcard |
| `${DOMAIN}` | CAA | absent, or includes `letsencrypt.org` | **hard fail** - a CAA record that omits LE kills issuance silently |
| `${DOMAIN}` | MX / SPF / DMARC | anything | warn only - matters once the app sends mail from the domain |

### 10.2 The checks

| # | Check | The failure message tells you |
|---|---|---|
| 1 | `dig +short A` for the apex and each subdomain - **and again against the authoritative NS** (`dig @$(dig +short NS ${DOMAIN} \| head -1)`) | which record is missing or points elsewhere. Querying authoritative means a stale local/ISP cache can't produce a false pass *or* a false fail during propagation. Any miss also writes the importable fixes of §10.4 |
| 2 | Resolved IP == target IP | the mismatch, both values, per record |
| 3 | Target IP not inside a known CDN/proxy range (Cloudflare et al.) | that the orange cloud must be off for at least `api` and `mercure` (SSE dies behind most proxies), or that you need a DNS-01 resolver instead |
| 4 | `dig CAA ${DOMAIN}` | the offending record verbatim, and the `issue "letsencrypt.org"` line to add |
| 5 | TCP 80 and 443 reachable on the target from here | that HTTP-01 will fail - check ufw / cloud security group |
| 6 | `ssh -o BatchMode=yes ${HOST} true` | key not loaded, wrong user, or host unreachable |
| 7 | Server sanity over SSH: Debian/Ubuntu, ≥ 2 GB RAM (else swap is added), ≥ 20 GB free disk, arch | exactly which one is short |
| 8 | Repo readable **from your machine**: `gh repo view ${REPO}`, falling back to `git ls-remote` | not authenticated / repo typo / no access |
| 9 | `REF` (branch or tag, default `main`) exists on the remote | the ref you asked for, and the refs that do exist |
| 10 | Repo readable **from the server**: `ssh ${HOST} git ls-remote ${REPO_URL}` | that the deploy key is missing → run `make deploy-key` |

Check 10 is the one that actually matters. Checks 8 and 9 pass on your laptop because your
laptop holds your GitHub credentials - the server does not, and that is precisely where
the first `git clone` fails.

### 10.3 Giving the server repo access - `make deploy-key`

```bash
make deploy-key HOST=deploy@203.0.113.42 REPO=acme/open-enu
```

A **repo-scoped, read-only deploy key** - not a copy of your personal SSH key. If the
server is compromised the blast radius is read access to one repository, not your account.

1. **Generate on the server** if absent: `ssh-keygen -t ed25519 -N '' -C "deploy@${DOMAIN}" -f ~/.ssh/id_ed25519_deploy`. Idempotent - an existing key is reused, never overwritten.
2. **Pin GitHub's host keys** so the first clone cannot hang on an interactive prompt:
   `ssh-keyscan -t ed25519 github.com >> ~/.ssh/known_hosts`, with the fingerprint compared
   against the value **fetched at run time from `https://api.github.com/meta`**
   (`ssh_key_fingerprints.SHA256_ED25519`) - not a constant baked into the script, which
   would break the day GitHub rotates. A mismatch is a hard fail, not a warning - an
   unverified `ssh-keyscan` is trust-on-first-use against an unauthenticated network.
3. **Write `~/.ssh/config`** on the server so git picks that key for `github.com`:
   `Host github.com / IdentityFile ~/.ssh/id_ed25519_deploy / IdentitiesOnly yes`.
4. **Register the public key on GitHub from your machine**, where the credentials live:

   ```bash
   gh api repos/${REPO}/keys \
     -f title="${PROJECT} ${ENV} ${DOMAIN} ($(date +%F))" \
     -f key="$(ssh ${HOST} cat ~/.ssh/id_ed25519_deploy.pub)" \
     -F read_only=true
   ```

   A key with the same fingerprint already registered → report and skip, don't duplicate.
   The title carries env + domain + date so keys stay attributable when you have six servers.
5. **Verify end to end**: `ssh ${HOST} 'ssh -T git@github.com'` must answer
   *"Hi acme/open-enu! You've successfully authenticated…"*, then
   `ssh ${HOST} git ls-remote ${REPO_URL}` must list refs. Only then does the target exit 0.

**No `gh`, or not authenticated?** The target prints the public key, the exact URL
(`https://github.com/${REPO}/settings/keys/new`), the title to paste, reminds you to leave
*Allow write access* unchecked, and waits for enter - then runs step 5 to confirm it worked.
The manual path is supported, not degraded.

**Rotation is manual by decision:** `make deploy-key KEY=id_ed25519_deploy_2027` generates
and registers a second key; delete the old one at `https://github.com/${REPO}/settings/keys`.
Automating it waits for a second server.

**Org forbids deploy keys?** `GIT_TOKEN=github_pat_…` switches the clone to HTTPS with a
fine-grained PAT written to the server's git credential store at `chmod 600`. Offered as an
alternative, not the default: a token is account-scoped and expires, a deploy key is neither.

### 10.4 Remediation output - three formats, no transcription

A diagnosis you have to retype into a DNS panel is half a tool. Whenever `dns-check` finds
anything missing or wrong it writes `.out/dns/${DOMAIN}/` (gitignored) and tells you which
file to use:

```
✗ DNS incomplete for open-enu.com → 203.0.113.42   (mode: explicit)

  status    type   name       current          expected
  ────────────────────────────────────────────────────────────────
  missing   A      @          –                203.0.113.42
  missing   A      app        –                203.0.113.42
  missing   A      manager    –                203.0.113.42
  wrong     A      api        198.51.100.7     203.0.113.42
  ok        A      mercure    203.0.113.42     203.0.113.42
  ok        A      www        203.0.113.42     203.0.113.42
  warn      CAA    @          issue "digicert.com"  ← blocks Let's Encrypt

Wrote:
  .out/dns/open-enu.com/zone.txt          → import at Cloudflare / most providers
  .out/dns/open-enu.com/cloudflare.sh     → applies only the 4 deltas via API
  .out/dns/open-enu.com/records.json      → machine-readable, for Terraform/Route53/etc.

Then re-run:
  make preflight HOST=deploy@203.0.113.42 DOMAIN=open-enu.com REPO=acme/open-enu
```

**1. `zone.txt` - BIND zone file, the universal import format.** Contains the *complete
desired record set*, not just the delta, so importing it with "overwrite existing records"
converges the zone in one action. Cloudflare takes it at **DNS → Records → Import and
Export → Import**; so do Route 53, Gandi, deSEC and most registrars.

```zone
$ORIGIN open-enu.com.
$TTL 300
; Generated by `make dns-check` - 2026-09-10 - target 203.0.113.42
; Cloudflare: DNS → Records → Import and Export → Import.
;   ⚠ Leave "Proxy status" set to DNS only. Proxying breaks Mercure SSE
;     (api. and mercure. at minimum) and hides the origin IP from HTTP-01.
@          300  IN  A  203.0.113.42
www        300  IN  A  203.0.113.42
app        300  IN  A  203.0.113.42
manager    300  IN  A  203.0.113.42
api        300  IN  A  203.0.113.42
mercure    300  IN  A  203.0.113.42
@          300  IN  CAA 0 issue "letsencrypt.org"
```

No `SOA` and no `NS` records - Cloudflare ignores them on import, and your provider owns
them anyway. `TTL 300` during setup so a mistake costs five minutes, not a day;
`make dns-check --ttl 3600` once the box is stable.

**2. `cloudflare.sh` - the delta, applied precisely.** A BIND import is blunt: it can
duplicate records if you forget the overwrite toggle. The API script instead `POST`s the
three missing records and `PATCH`es the one wrong record by id, leaving everything else
untouched. Needs one env var and nothing else:

```bash
CF_API_TOKEN=… bash .out/dns/open-enu.com/cloudflare.sh          # dry-run, prints the calls
CF_API_TOKEN=… bash .out/dns/open-enu.com/cloudflare.sh --apply  # executes them
```

It resolves the zone id from `${DOMAIN}`, sets `"proxied": false` explicitly on every
record, is safe to re-run (a record that already matches is skipped, not duplicated), and
prints a one-line diff per change. The token needs exactly `Zone:DNS:Edit` on that one zone -
the script prints that requirement and the token-creation URL if the call 403s. The token
is read from the environment and never written into the generated file.

**3. `records.json` - the same desired state, provider-neutral.** For Terraform
(`cloudflare_record` / `aws_route53_record` `for_each`), a Route 53 change batch, or your
own automation:

```json
{
  "domain": "open-enu.com", "target": "203.0.113.42", "mode": "explicit", "ttl": 300,
  "records": [
    { "type": "A", "name": "@",   "value": "203.0.113.42", "proxied": false, "status": "missing" },
    { "type": "A", "name": "api", "value": "203.0.113.42", "proxied": false, "status": "wrong",
      "current": "198.51.100.7" }
  ]
}
```

**`make dns-apply`** is the opt-in shortcut: it runs `cloudflare.sh --apply`, waits for the
authoritative NS to serve the new values, and re-runs `dns-check`. It is a separate target
and is **never** invoked by `buildprod` - see §10.5.

#### Explicit records vs wildcard

`DNS_MODE=explicit` (default) emits the six named records. It works on every provider and
every Cloudflare plan, and a typo'd subdomain returns NXDOMAIN instead of silently hitting
your app.

`DNS_MODE=wildcard` emits `@` plus `*` - choose it when the product will hand tenants their
own subdomain (`acme.open-enu.com`). Caveat the script prints: **a proxied wildcard is
Cloudflare Enterprise-only**, so on Free/Pro the wildcard must be DNS-only. That is what we
want anyway, but it surprises people who expect the orange cloud to be available.

### 10.5 What preflight deliberately does not do

It **generates** DNS changes; it does not **apply** them unless you explicitly run
`make dns-apply` with a token you supplied. Build commands never mutate DNS as a side
effect - a `buildprod` that quietly rewrites a live zone is not a tool anyone should trust
near production.

Same principle for credentials: preflight tells you to run `make deploy-key`, rather than
generating and registering keys behind your back as part of a build.

---

## 11. Configuration & secrets

- **`.env`** (repo root, gitignored, `chmod 600`) - the only file you edit by hand. Holds
  `PROJECT_NAME`, `DOMAIN`, `APP_STAGE`, credentials. Generated from `.env.example` by
  `scripts/lib/envgen.sh`.
- **Per-app env files are derived, never hand-written.** `envgen.sh` writes
  `backend/.env.local`, `frontend/.env`, `manager/.env`, `landing/.env` from `DOMAIN` +
  the shared secrets, so `api.${DOMAIN}`, CORS origins and Mercure URLs can never disagree.
- **Nothing secret is ever committed.** `.env*`, `docker/certs/`, `backend/config/jwt/`,
  `docker/traefik/acme/`, `.out/` are gitignored; `.env.example` documents every variable
  with a comment and a safe default.
- **Optional integrations are no-ops when empty**: `SENTRY_DSN`, `BACKUP_REMOTE`,
  `SMTP_DSN` (falls back to Mailpit in dev, refuses to start in prod without one). A
  boilerplate must boot with zero external accounts and must be loud, not silent, about
  what is unconfigured in prod.
- **Secrets are env vars, and that is a known trade-off.** They are visible to
  `docker inspect` and to any process in the container. Acceptable for a single-host
  compose deployment; a project that needs more moves to Docker secrets or a vault, and
  `envgen.sh` is the one place to change.
- **`make env-check`** diffs `.env` against `.env.example` and reports missing keys - run
  as the first step of every build target, so a new variable added upstream surfaces
  immediately instead of as a runtime crash.

---

## 12. Designed to be read and extended by agents

The thesis, operationalized. Modelled on the reference framework's harness, with the debt-control
mechanisms made explicit - and, as of v2, with an honest account of which checks are
blocking, which are advisory, and how each one actually works.

### 12.1 Readable - bounded, derivable, machine-parseable

**Bounded context.** Any single feature task must be completable after reading:
root `AGENTS.md` (routing only) + the target module's `MODULE.md` + the `Example` module.
That is the budget, and it is measured - `make agents-budget` fails if:

| Budget | Limit | Why |
|---|---|---|
| Root `AGENTS.md` | 32 KB | Past this, tools truncate it and the rules silently stop applying |
| Any `MODULE.md` | 8 KB | If a module needs more explaining than that, the module is too big |
| Any single module | ~2,500 LOC / 40 files (initial values; tune after Phase 6) | The unit an agent holds in working memory. Over the line = split it |
| `Example` module | 850 LOC **including tests** | It is read on every task; it must stay cheap. This is why `Example` is minimal and `Identity` is the full reference (§6.7). **Measured, not guessed:** Phase 6 landed it at exactly 800 for fourteen platform services, and Phase 7 raised it to 850 when search became a fifteenth thing it must demonstrate. The number moves only when the list of what the module must SHOW grows - never to accommodate waste |

A module that outgrows the budget is a design signal, not a documentation problem. The
check reports it; splitting it is the fix.

**Derivable paths.** Given "add invoicing", every location follows mechanically:

```
backend/src/Module/Invoicing/{Contract,Controller/Api,Dto,Entity,Repository,Service,Event,Security/Voter,Acl,Migrations,Fixtures,Tests}
frontend/app/modules/invoicing/{nuxt.config.ts,navigation.ts,i18n/{pl,en}.json,pages,components,composables,stores,types}
.ai/platform/specs/2026-09-10-invoicing.md
AGENTS.md → Task Router row "Invoicing"
```

No judgement calls, so no two agents place the same thing differently. `make module
NAME=Invoicing` generates the whole tree **including the Task Router row and a stub
`MODULE.md`**, so the first commit already has the right shape and the harness grows with
the code instead of being written from memory at the end.

**Machine-readable over prose.** Agents parse structure reliably and prose unreliably, so
the facts an agent needs are data files, not paragraphs:

| File | Answers |
|---|---|
| `module.yaml` | name, description, `depends`, `enabled`, owner |
| `Acl/permissions.php` | every permission string this module defines |
| `backend/openapi.json` (generated, committed) | every endpoint, its DTO shape, its auth requirement - and the source of the frontend types |
| `.ai/inventory.json` (generated) | **what already exists** - services, contracts, composables, UI components, with one-line purposes. Goes into the agent's context before it writes anything |
| `make module:list` | resolved routes, entities, migrations per module |

**Determinism.** Fixtures use fixed UUIDs and `ClockInterface` is frozen in tests, so a
functional test's output is byte-reproducible and an agent can assert on it rather than
guess at it.

### 12.2 The harness files

- **`AGENTS.md`** (root) - `Always` / `Ask First` / `Never` / `Validation Commands` /
  **Task Router** mapping "what you're about to do" → "which guide to read first" / repo
  structure / where to put code. `CLAUDE.md` is `@AGENTS.md`. **Grown per phase**: each
  phase's acceptance includes its Task Router rows and guides existing.
- **Nested `AGENTS.md`** in `backend/`, `frontend/`, `manager/`, `landing/`, `docker/` -
  local imports, conventions, validation commands.
- **`MODULE.md` per module** - what it owns, its public contracts, its events, its ACL
  strings, what other modules may and may not do with it.
- **`.ai/platform/docs/`** - long-form procedure: `module-development.md`, `platform-services.md`
  (§6.9 with worked examples), `extension-surfaces.md` (§6.10), `tenancy.md`, `auth.md`,
  `events-and-outbox.md`, `commands-and-audit.md`, `i18n.md`, `frontend-conventions.md`,
  `deployment.md`, `testing.md`, `env-vars.md`, `troubleshooting.md`.
- **`.ai/platform/specs/`** - `{YYYY-MM-DD}-{kebab-title}.md`, written before non-trivial features.
- **`.ai/platform/adr/`** - decisions with their rationale, so an agent doesn't "fix" a deliberate
  choice. §2 of this plan seeds the first twelve.
- **`.ai/platform/lessons/`** - one file per correction, indexed, so a mistake made once stops recurring.
- **`.ai/platform/skills/`** (symlinked into `.claude/skills/`) - `create-module`, `add-endpoint`,
  `review-change`: the end-to-end checklists that turn a convention into a procedure.

### 12.3 Extendable without debt - every rule is executable

Documentation cannot enforce anything. Each convention below ships with the check that
fails when it is broken. **Blocking** checks run in `make ci` and fail it; **advisory**
checks print and never block - a guardrail that fires falsely gets fixed or deleted, never
muted per-file (§15).

| Convention | Check | Mechanism | Mode |
|---|---|---|---|
| No cross-module ORM relations or entity imports | `arch` | PHPStan rule: only another module's `Contract\` and `OpenEnu\Kernel\*` cross module boundaries | blocking |
| No cross-module DB foreign keys | `arch` | Migration linter maps tables → modules, fails on a cross-module `FOREIGN KEY` | blocking |
| Controllers stay thin | `arch` | PHPStan: no `EntityManager` in controllers, max method length | blocking |
| Raw SQL only in `Repository/` with `#[Unscoped]` | `arch` | PHPStan rule on `Connection::*`/`createNativeQuery` call sites | blocking |
| Every tenant-owned entity is filtered | `arch` | Test enumerates `TenantScopedInterface` implementors and asserts filter coverage | blocking |
| Every endpoint has an ACL rule | `arch` | Test walks the router, fails on any route with no voter/permission/`PUBLIC_ACCESS` declaration | blocking |
| **Every endpoint has a functional test** | `arch` | **Runtime tracing**: a test listener records every `_route` hit during the functional suite; afterwards `router ∖ hit` must be empty, minus routes carrying `#[NoTestRequired('reason')]` | blocking |
| Every module has `MODULE.md` + `module.yaml` + Task Router row | `module:check` | File presence + a grep of `AGENTS.md` for the module name | blocking |
| Docs don't reference files that don't exist | `docs:check` | Link-walks every `.md` | blocking |
| `MODULE.md` matches code | `docs:check` | Its "Contracts" and "Events" sections must list exactly the files in `Contract/` and `Event/` | blocking |
| No new top-level folder types | `module:check` | Directory allowlist per module; an invented folder fails the build | blocking |
| Migrations match entities | `arch` | `doctrine:schema:validate` + "no pending diff" | blocking |
| `openapi.json` is current | `arch` | Regenerate and `git diff --exit-code` | blocking |
| Env var added but undocumented | `env-check` | Diffs `.env.example` against `%env()%` / `process.env` usage | blocking |
| **Dead code** | `arch` | PHP: container introspection (`debug:container`, `debug:router`) - a class in `src/` that is neither a service, a route target, an entity nor a test is reported. TS: `knip`, with every Nuxt convention it cannot see declared in `knip.config.ts` **with its reason** | blocking for TS; **advisory for PHP, permanently** - a class can be reached by a mechanism no static view of the container shows (a Doctrine type registered by name, a migration addressed by version, a fixture named in a string) |
| **Possible duplicate helper** | `inventory` | Name-similarity against `.ai/inventory.json` (Levenshtein on export names + same-module heuristic). Two helpers doing the same job rarely share a signature, so this can only ever be a hint | **advisory** |
| Writes only via command bus; cache/storage only via kernel contracts | `arch` | PHPStan rules of §6.4 | blocking |
| Every PUT/PATCH entity is versioned; every `user_id` entity is a `GdprSubject` | `arch` | Router + entity-metadata walk | blocking |
| No raw user-facing strings; every key exists in both `pl` and `en` | `i18n-check` | PHPStan rule + `eslint-plugin-vue-i18n` + key-set diff | blocking |
| `#[ClientBroadcast]` events are listed in `MODULE.md` | `docs:check` | Cross-check | blocking |
| Context budgets | `agents-budget` | Byte/LOC counts per §12.1 | blocking |

The rule for extending the boilerplate itself: **adding a convention means adding its
check in the same change.** A convention without a check is a convention that will be gone
in three months.

### 12.4 Agent failure modes, and what catches each

Agents produce debt in characteristic, predictable ways. Naming them makes them addressable:

| Failure mode | What it looks like | Counter-mechanism |
|---|---|---|
| **Reinvention** | A second date formatter, a second API wrapper | `.ai/inventory.json` in context before writing + advisory similarity hint + "search before you write" in `Always` |
| **Partial application** | Fixes one call site, leaves four | `make ci` runs the whole suite, not the touched files; grep-based consistency checks for known patterns |
| **Silent scope drift** | Adds a field; no migration, no DTO, no test, no ACL | The endpoint/ACL/test/openapi/schema checks above make the omission a build failure |
| **Copy-paste residue** | `Example`'s leftovers in the new module | `make module` templates from a parameterized skeleton, never a file copy; a `TODO(scaffold)` marker check fails if any survive |
| **Abandoned scaffolding** | Generated files nothing imports | Dead-code check |
| **Test theater** | Tests asserting the implementation, restating mocks | Functional tests hit real HTTP + real DB; route tracing means a test must actually exercise the route; `Example` demonstrates behavioral assertions |
| **Doc drift** | `MODULE.md` describes a shape the code lost | `docs:check` cross-checks Contracts/Events sections against the folder contents |
| **Boiling the ocean** | A "small fix" touching nine modules | `Ask First` covers multi-module changes; spec-first for 3+ step work |
| **Confident wrong deploy** | Ships without verifying | `deploy*` refuses to run with a red `make ci`; deep health gate after |
| **Slow loop → skipped verification** | Agent stops running checks because they take 8 minutes | `make check` (< 60 s) is the inner loop; `make ci` is the gate, not the loop |

### 12.5 Two speeds of verification

| Target | Runs | Budget | When |
|---|---|---|---|
| `make check` | PHPStan + arch rules on changed files, typecheck for the touched Nuxt app, unit tests for touched modules, `module:check`, `docs:check` | **< 60 s** | After every edit - the agent's inner loop |
| `make ci` | Everything in §13, all apps, functional suite with DB, route tracing, openapi diff, inventory, budgets | minutes | Before commit, before `deploy*`, in GitHub Actions |

If `make check` creeps past 60 s, that is a bug in the harness with the same priority as a
failing test - a slow loop is a loop that stops being run.

### 12.6 Reversibility

Debt is survivable when it is cheap to undo. One module per change, conventional commits,
spec-first for anything non-trivial, and migrations that are always additive-then-cleanup
(never a destructive change in the same release as the code that stops using the column).
Every remote deploy takes a DB dump first (§9.1) and the nightly backup is off-box and
restore-tested (§9.3), so "revert" is a real option and not a hope.

---

## 13. Testing & quality gates

| Gate | Command | Runs in |
|---|---|---|
| PHP static analysis (level 8 + custom boundary rules) | `make stan` | api container |
| PHP style | `make lint` | api container |
| PHP unit | `make test-unit` | api container |
| PHP functional (isolated test DB, migrated per run, route tracing on) | `make test-functional` | api container |
| Nuxt typecheck ×3 apps, against the generated API types | `make typecheck` | node containers |
| ESLint ×3 apps + `knip` | `make lint` | node containers |
| Vitest component/composable tests | `make test-ui` | node containers |
| **Playwright** - login with httpOnly cookies, tenant switch, impersonation banner, SSE arrival, upload, 409 conflict bar | `make e2e` | e2e container against the dev stack; **`ci` only, never `check`** |
| i18n completeness | `make i18n-check` | host |
| Module boundaries, no cross-module FKs, raw-SQL rule, ACL coverage, route-test coverage, tenant-filter coverage, schema/openapi freshness, PHP dead code (advisory) | `make arch` | api container |
| Docs/link integrity, `MODULE.md` ↔ code, Task Router rows, context budgets | `make docs:check` `make module:check` `make agents-budget` | host |
| Inventory freshness + similarity hints (advisory) | `make inventory` | host |
| Env vars documented | `make env-check` | host |
| Generated types current | `make openapi-check` (in `make arch`), then `make types` | host |
| Fast subset of the above | **`make check`** | mixed, < 60 s |
| Everything, in CI order | `make ci` | all |

`make ci` is what GitHub Actions runs, so "green locally" and "green in CI" mean the same
thing. Git hooks (`.githooks/pre-commit`, installed by `builddev`) run `make check`.

**Security-specific tests that must exist** (each is a named acceptance criterion in §14):

- Tenant A cannot read, write, or receive SSE for tenant B's data.
- Empty `TenantContext` returns zero rows, not all rows.
- Tenant token with a manager's email is rejected on `/api/manager`; manager token rejected on `/api`.
- Impersonation token cannot refresh and is denied on `#[DeniedUnderImpersonation]` routes.
- A third CORS origin is refused; refresh cookie is `httpOnly` and never appears in a JSON body.
- Reset/invite tokens are single-use and expire.
- Rate limiters return 429 at the configured thresholds.

---

## 14. Implementation phases

Each phase ends in a state where `make builddev` still works. No phase leaves the repo broken.
**The harness checks are built in Phase 2 and every later phase is developed under them** -
v1 had them in Phase 9, which meant building eight phases without the guardrails the plan
is about.

| # | Phase | Deliverable | Done when |
|---|---|---|---|
| **0 ✅** | Skeleton, harness, `init`, packaging | Folders incl. `backend/kernel/` (composer path package) and root npm workspaces, `.env.example`, gitignores, `AGENTS.md` skeleton with an empty Task Router + `.ai/` scaffolding (docs stubs, ADR-0001…0015 from §2 and the non-goals, skills dir + symlinks), `Makefile` with `help`/`env`/`check-tools`/`init` | `make help` prints the grouped list; `make env` produces valid env files; `make init NAME=x` renames the app and **not** the kernel namespace, and is a no-op the second time; `composer show open-enu/kernel` resolves to the path repo |
| **1 ✅** | Dev Docker stack | `compose.dev.yml`, Traefik + mkcert + hosts scripts **with the WSL2 branch**, empty Symfony skeleton + three Nuxt shells | `make builddev` → all six URLs answer over trusted HTTPS on Linux **and** on this WSL2 machine (Windows browser) |
| **2a ✅** | Kernel: module system **+ the guardrails** | Module contract, registry, discovery passes (services/routes/entities/migrations/ACL/openapi), `app:module:create` (Task Router row + `MODULE.md` stub + navigation/i18n stubs), exception listener, API DTOs, shallow + deep health, fail-closed N-column `ScopeFilter`, outbox middleware, `RequestId` + JSON logging with context processor, `LocaleResolver` + translation wiring; `.ai/platform/docs/extension-surfaces.md`; **all §6.4 PHPStan rules, `module:check`, `docs:check`, `agents-budget`, `i18n-check`, `make check`, `make arch`** | `make module NAME=Demo` creates a routed, migratable, documented, i18n-ready module with no manual wiring; every check listed here **demonstrably fails on a deliberately broken fixture** and passes on the clean tree; `make check` < 60 s; nothing in `kernel/` imports `App\` |
| **2b ✅** | Kernel: platform contracts | **Command bus + audit middleware skeleton, `TenantCache`, `VersionedInterface` + `ConflictBody` 409 path, `StorageInterface` (local adapter), typed `DomainEvent` + `#[ClientBroadcast]`, `SearchIndexerInterface` (tsvector), `TenantSetupInterface` + runner, `GdprSubjectInterface` + walker, `ProgressReporterInterface`, `FlagsInterface` + `#[Flag]`, `KeyProviderInterface` + `#[Encrypted]` type**; `.ai/platform/docs/platform-services.md`; the §6.9 PHPStan rules (`flush()` / cache / storage / versioned / gdpr) | A `Demo` update returns 409 on a stale version; a `Demo` write outside a handler fails `arch`; each contract has one kernel-level unit test and a LOC cap in `agents-budget`; `Demo` seeds a row via `TenantSetupInterface` on `make seed` |
| **3 ✅** | Tenant + Identity + ApiKey + Audit + Attachment + Progress | Tenant with `SetupRunner`, `TenantContext`, filter + the three bypass closures, voter, cascade cleanup; User (**encrypted email + hash**), Membership, email-verified registration, login, rotating refresh via cookie, switch-tenant, password reset, invitations (hashed single-use tokens), locale preference, avatar via Attachment, rate limiters; **`#[Encrypted]` type + HKDF `KeyProviderInterface` + `encryption:rotate`; ApiKey module + authenticator; `app:gdpr:export/erase` with Identity implementing `GdprSubjectInterface`**; `TenantStamp` + `RequestIdStamp` + worker middleware; Audit module wired to the command bus; **Attachment module (Flysystem local + S3 config, signed URLs, orphan sweep); Progress module** | Security tests from §13: cross-tenant read/write denied, empty context → zero rows, un-stamped message → zero rows, cookie is `httpOnly`, tokens single-use, 429s at threshold; **API key with a permission subset can list but not create; revoked key → 401; encrypted email is ciphertext in `psql` and login still works; `gdpr:export` returns the user's data, `gdpr:erase` leaves no `user_id` rows; an upload lands under `tenants/{tid}/…` and its signed URL expires** |
| **4 ✅** | Manager + Settings | PlatformManager, separate firewall + provider, **`aud` enforcement**, tenant admin endpoints, **impersonation per §6.6** with `ImpersonationGuard` + audit + banner contract, audit viewer API, scheduler status API; **Settings module: typed settings + flags, global → tenant resolution, `#[Flag]` guard, tag-cached, audited, manager CRUD + tenant-override endpoints** | **Tenant token bearing a manager's email is rejected on `/api/manager`**; manager token rejected on `/api`; impersonation token can't refresh and is denied on guarded routes; every impersonated request is in the audit log; **a flag flipped for tenant A changes A's `#[Flag]` route to 404 and not B's, within one request (cache tag invalidation)** |
| **5 ✅** | ui-kit + three apps + e2e | Shared layer as `@open-enu/ui-kit` (cookie-based `useApi` with `ConflictError`, in-memory auth store, tenant-scoped `useMercure`, **`useAppEvent`, `useProgress`, `useFlags`, `useUpload`, `useConflict`**, toast, UI primitives incl. `ImpersonationBanner` + conflict bar + upload + progress, `Injection` spots, `defineNavigation`/`defineSettingsTab`, Tailwind theme, **generated types, `pl`/`en` i18n**); frontend shell with auth + tenant switcher + settings (profile, API keys, webhooks placeholder); manager shell with audit viewer, flags editor, scheduler view; landing with real content in `pl` + `en`; module-as-layer wiring; **Playwright harness + the five specs of §13** | Login → dashboard works in `frontend` and `manager`; switching tenant re-scopes the UI; `make types` output is committed and current; landing renders markdown in both locales; **`make e2e` green: cookie login, tenant switch, impersonation banner, SSE arrival, 409 conflict bar**; `i18n-check` green |
| **6 ✅** | Example module | Minimal `Project` per §6.7: versioned, scoped, one encrypted field, one attachment, `attributes` JSONB; list/create/update; command + handler; voter; `ProjectCreated` with `#[ClientBroadcast]`; progress-reporting archive handler behind `#[Flag('example.archive')]`; `TenantSetupInterface` seed; fixture; unit + functional test (as user and as API key); page with i18n; navigation entry; one `#[DeniedUnderImpersonation]`; one Playwright spec | Create a project in the UI → list updates live via `useAppEvent`, and **tenant B's stream receives nothing**; stale update → conflict bar; archive shows progress; flag off → route 404; `Example` ≤ 800 LOC incl. tests; all §12.3 gates green; PHP dead-code check promoted from advisory to blocking if deterministic through Phases 3–6 |
| **7 ✅** | Notification, Webhook, Search | Notification type registry + `RealtimePublisherInterface` + translated email via Messenger + Twig + in-app feed; **Webhook module: endpoints, Standard Webhooks signing, jobs-queue delivery with retry, delivery log, redeliver, encrypted secret, frontend settings page**; `Example.name/description` indexed via `SearchIndexerInterface` + `/search` endpoint; scheduler examples (unverified-tenant purge, attachment orphan sweep); Mailpit + Sentry + optional OTel wiring | Registration email lands in Mailpit in the user's locale; a `ProjectCreated` reaches a test webhook receiver with a valid signature, a failing receiver is retried 5× and visible in the log; search finds a project by a word in its description; a failed message lands in the Doctrine `failed` transport and `messenger:failed:retry` replays it |
| **8 ✅** | Preflight & remote provisioning | `preflight.sh`, `deploy-key.sh` (runtime fingerprint from `api.github.com/meta`), `provision.sh` with **lockout-safe SSH hardening**, `compose.prod.yml` (pinned images, `APP_STAGE`), Traefik/LE config, systemd unit, **off-box encrypted backup timer + `backup-verify` + `restore`**, `buildstaging`/`buildprod`/`deploy*` | `make preflight` diagnoses missing/wrong/CAA/no-repo-access and emits importable fixes; `make deploy-key` makes check 10 pass; a fresh VPS goes bare → all-subdomains-live in one command; re-running preserves secrets and data; **`make backup-verify` restores last night's remote dump into a scratch DB and validates it**; **`buildprod` without `BACKUP_REMOTE` exits non-zero after the health gate with the fix printed, `buildstaging` completes with the nightly warning armed**; hardening never runs unless the second `deploy` login succeeded |
| **9 ✅** | Agent-readiness completion, MCP & CI | Remaining checks that need real code: `.ai/inventory.json` generator + similarity hint, route-tracing coverage, `knip`, openapi diff; **`app:mcp:serve` - an MCP server generated from `openapi.json` + ACL, authenticating with an API key, so any agent can operate a OpenEnu app as a tool**; GitHub Actions running `make ci` incl. `e2e`; Dependabot (composer, npm, **docker**); `README.md`; full `.ai/platform/docs/` pass; every `MODULE.md`; `.ai/platform/skills/` | CI green on a clean clone; every §12.3 check demonstrably fails on a broken fixture; an agent adds a working module using only the repo's own docs with `make check` as its loop, and every gate stays green; an MCP client lists and creates a project through the generated server with a scoped key |

Phases 3–4 and 5 can proceed in parallel once Phase 2b lands. Phase 8 depends only on
Phase 1's compose structure, so it can start early if getting a staging box up is urgent.

**Phase 2 is split so the first milestone is clean.** 2a delivers a working module system
under its guardrails and is a shippable checkpoint on its own. 2b then adds every platform
contract *before* any module exists to use them, so Phases 3–7 consume contracts instead of
inventing local versions - the exact debt this plan exists to prevent. 2b's implementations
are thin (a tsvector indexer is ~80 lines; the flags interface is one method); the weight
is in the number of contracts, not their depth.

---

## 15. Risks & things to watch

| Risk | Mitigation |
|---|---|
| Module auto-discovery gets subtly wrong (entity mapped twice, route collision) | `app:module:list` prints the resolved wiring; a functional test asserts the Example module's routes/entities/migrations are all discovered |
| A tenant leak slips through async code | `TenantStamp` + worker middleware + **fail-closed filter** - an un-stamped message returns nothing; a worker-side test asserts it |
| A tenant leak through the identity map after `runUnscoped()` | `runUnscoped()` clears the EM on exit, in a `finally`; a test loads unscoped then asserts a scoped `find()` misses |
| A tenant leak through Mercure | Topics embed `tid`; subscriber JWT is restricted to `/tenants/{tid}/**`; test subscribes as A, publishes to B, asserts silence |
| Realm crossing via shared email | `aud` claim enforced per firewall; the acceptance test uses a deliberately shared email |
| Impersonation becomes a privilege-escalation path | Short TTL, non-refreshable, guarded routes, audit on every request, visible banner |
| `buildprod` re-run destroys data or rotates secrets | Never-overwrite-`.env` rule, explicit `--force` flag required for anything destructive, DB backup taken before migrations |
| **SSH hardening locks the operator out** | Hardening runs only after a *second* connection as `deploy` succeeds; otherwise `sshd_config` is untouched |
| Backup exists but restore doesn't work | `make backup-verify` restores into a scratch DB; part of Phase 8 acceptance |
| Backup is on the same disk as the DB | Off-box via `rclone`; a nightly warning is logged until `BACKUP_REMOTE` is set |
| Let's Encrypt rate limits during setup iteration | `LE_STAGING=1` supported on `buildstaging`; ACME storage persisted and backed up |
| DNS looks right locally but is stale/unpropagated | Preflight queries the authoritative NS as well as the local resolver |
| Cloudflare proxying breaks Mercure SSE or hides the real origin | Preflight flags CDN IP ranges and names the subdomains that must be DNS-only |
| A CAA record silently blocks Let's Encrypt | Hard-fail check with the exact `issue "letsencrypt.org"` line to add |
| A Cloudflare BIND import silently turns proxying on and breaks SSE | The generated `zone.txt` carries the warning inline; `cloudflare.sh` sets `"proxied": false` explicitly |
| Re-importing a zone file duplicates records | The zone file is the full desired state (import with overwrite); the delta path is the API script, which patches by record id |
| First `git clone` on the server hangs on host-key verification | `ssh-keyscan` verified against the fingerprint fetched from `api.github.com/meta` at run time |
| A personal SSH key ends up on a production box | `deploy-key` only ever creates repo-scoped read-only keys, generated on the server |
| `:latest` image silently changes under prod | Every prod image pinned; Dependabot `docker` ecosystem opens the bump PRs |
| Domain event handlers act on uncommitted or rolled-back data | Outbox on the Doctrine transport in the same transaction |
| WSL2 file-watcher limits break Nuxt hot reload; Windows browser doesn't trust mkcert or resolve hosts | `os.sh` WSL2 branch: Windows hosts file, Windows cert store, inotify check |
| Conventions decay as agents generate code around them | §12.3: no convention ships without its check; the checks exist from Phase 2 |
| A module grows past what fits in context, and quality falls off a cliff | `make agents-budget` turns it into a build failure with "split this" as the fix |
| The guardrails themselves become the debt (slow, flaky, false-positive CI) | Blocking vs advisory is explicit; `make check` has a 60 s budget treated as a bug when exceeded; a check that fires falsely gets fixed or deleted, never muted per-file |
| Phase 2 balloons because "thin" contracts grow implementations | Each kernel service has a LOC cap in `agents-budget`; anything past it moves to a module |
| `kernel/` and `src/` drift into each other (kernel importing `App\`) | PHPStan rule + Phase 0 acceptance; `composer validate` on the kernel package in `make ci` |
| i18n discipline collapses under generated code (raw strings creep in) | `i18n-check` is blocking from Phase 2; `make module` scaffolds the key files |
| The platform-services table becomes a second, unread manual | It is the *first* Task Router row and `make module` prints it; `Example` uses every line of it |
| `Example` drifts from "minimal" as people add "just one more pattern" | 600 LOC budget is blocking; harder patterns live in `Identity` and the Task Router points there |
| Boilerplate rots because it is never used | `make init` makes starting a product from it one command |

---

## 16. Open questions

None open. Every question raised in v1–v3 is resolved in §2 and the non-goals:

| Question | Resolved as |
|---|---|
| Project renaming | Yes - `make init`, Phase 0 |
| Server user | `deploy` |
| API docs | Yes - `nelmio/api-doc-bundle`, Phase 2a |
| Membership model | `Membership` from day one |
| Token transport | httpOnly cookies |
| Domain events | Doctrine-transport outbox |
| Kernel packaging | Yes - path package from Phase 0; publishing per-project, later |
| Custom fields | No - `attributes JSONB` (ADR-0013) |
| Hierarchical organizations | Wait for demand (ADR-0014) |
| Undo/redo | No - command snapshots keep the door open (ADR-0015) |
| `ui-kit/` location | Top-level, stays |
| DNS apply scripts | Cloudflare only in v1 |
| Deploy-key rotation | Manual |
| Landing content | Markdown |
| Backup destination | `buildprod` refuses without one; `buildstaging` warns |
| Phase 2 | Split into 2a / 2b |
| CORS policy | Exact origins from `APP_URL`/`MANAGER_URL`, never a pattern (ADR-0022) |
| Frontend locale files | `<layer>/i18n/locales/{en,pl}.json` + a `.ts` re-export the loader points at |
| SSR | Off for `frontend` and `manager` (no session to render with), on for `landing` |

New questions go here as they arise; a question that survives two revisions becomes an ADR.

---

## 17. Changelog

### v3.6 → v3.7 - the OpenEnu rename and the shared edge

| Change | Why | Applied in |
|---|---|---|
| **The framework is OpenEnu**: `open-enu/kernel`, `OpenEnu\Kernel\`, `@open-enu/ui-kit` | Renamed before the first commit, the only moment it costs nothing. ADR-0016 is amended, not reversed | §2, ADR-0016 |
| **Framework identifiers never contain the slug** - `open_enu_*`, `open_enu.*`, `openEnu.*` | `make init` rewrote `bundles.php`, the route loader type and two service tags, and the renamed app did not boot. `selftest` now counts every framework identifier across init | §9 |
| **One edge proxy per Docker host** (`enu-edge`), not a Traefik per project | Two projects could not run side by side: both wanted ports 80/443, and their router names collided in each other's Traefik | §8.1, `.ai/platform/specs/2026-09-17-open-enu-and-shared-edge.md` |
| **Staging and production on one server or two**, every name stage-scoped (`<slug>-staging`, `<slug>-prod`) | They resolved to one checkout, one compose project and one database volume; `buildstaging` on a production box would have taken production over | §9, `.ai/platform/specs/2026-09-17-staging-and-promotion.md` |
| **Staging is the dev stack behind an IP allowlist**; `make promote` ships staging's exact SHA through GitHub | Vibe coding happens on staging; production must get what was looked at, not a branch head that moved | `.ai/platform/docs/deployment.md` |
| **The server edge renders its ACME config** | `traefik.prod.yml` wrote `${LETSENCRYPT_EMAIL}` in a file Traefik does not interpolate - no certificate could have issued | `docker/edge/traefik.server.yml` |
| **Mercure moves to `api.${DOMAIN}/.well-known/mercure`; the subscriber cookie is host-only** | `Domain=.example.com` reached `*.stg.example.com` - production's subscriber token was sent to the staging stack. `PlatformEndpointsTest` fails if the cookie gets a domain again | §5, §6.5 |
| **`prod-check` discovers routers instead of listing them** | Prefixed router names matched none of the listed ones, and the check would have skipped every router and passed | §12.3 |

### v3.5 → v3.6 - what Phase 9 changed in the design

| Change | Why | Applied in |
|---|---|---|
| **`runUnscoped()` refuses to run with unsaved work in the UnitOfWork** | It clears the EntityManager on the way out - the isolation guarantee - which also detaches the CALLER's entities. Email verification and tenant suspension both wrote nothing at all, silently, for exactly that reason. An API whose failure mode is "nothing happens" has to be made to throw | §6.2, [[unflushed-work-does-not-survive-rununscoped]] |
| **`TenantTargetedInterface` - a command names the tenant it acts on** | The audit entry took its tenant from the ambient scope, which the manager realm does not have. Suspensions, impersonations and kill switches were written with no tenant and vanished from the one query anybody runs: *what happened to this customer?* | §6.4 |
| **Route coverage is blocking, with zero exemptions** | Route tracing found 32 untested endpoints, and writing their tests found three product bugs. The one `#[NoTestRequired]` turned out to name a reason that was not true, so it was tested instead and the attribute removed | §12.3 |
| **The audit trail tie-breaks on the id** | `recordedAt` is second-precision, so two entries in the same second came back in whatever order Postgres chose. A page whose order changes between two reads is not a trail | §6.4 |
| **`/api/manager/workers` tolerates a missing `messenger_messages`** | Messenger creates the table lazily, so the operator console 500'd on a fresh install - the state it is least allowed to crash in | §6.7 |
| **`.ai/inventory.json` is committed and drift-checked** | The point is to be in context BEFORE anything is written. A file an agent has to run a command to produce is a file it will not have | §12.3, §12.4 |
| **The similarity hint is advisory, permanently** | Two helpers doing the same job rarely share a signature. A blocking version would be wrong often enough to get muted, which costs more than the duplicates | §12.3 |
| **`knip` config is TypeScript, not JSON** | Every Nuxt convention it cannot see needs a reason attached. An unexplained ignore is indistinguishable from a muted failure | §12.3 |
| **MCP tools are filtered to declared module permissions** | One rule replaces a list of exclusions: an API key cannot sign in, cannot refresh a browser session, and is refused by the operator firewall on its `aud` claim. Offering those endpoints produces an agent that spends its turns discovering which of its tools are permanently 401 | §6.10, §14 |
| **The MCP server calls the API over HTTP, not in-process** | The agent is a client, and must pass the same firewall, tenant scope and permission checks as any other machine caller. In-process would hand it more access than its credential | §14 |
| **`app:apikey:create`** | Minting a key required a browser session, so setting up a machine client started with a person logging in and pasting a secret out of a web page | §6.7 |
| **`DEFAULT_URI` is the API's canonical URL**, rather than a new variable | It is already derived from `DOMAIN` and already means exactly that; a second variable meaning the same thing is a second variable to disagree | §11 |
| **The "every endpoint has an ACL rule" check now exists** | §12.3 had claimed it since v2 and nothing implemented it. Found by scaffolding a module and running the gate: the scaffold shipped an action with no `#[IsGranted]`, reachable by every signed-in user of every role, and nothing said a word. `tests/Arch/AccessControlCoverageTest.php` walks the real router; a deliberately open action declares `IS_AUTHENTICATED_FULLY` rather than omitting the attribute | §12.3 |
| **`make module` ships a guarded route and a functional test** | The acceptance criterion was "an agent adds a module using only the repo's docs and every gate stays green". A fresh scaffold failed `arch` three ways - no ACL, no functional test for its route, stale spec/inventory - and its "Next" steps mentioned none of them. A module that starts red teaches the next person that red is normal | §14, `scripts/dev/module.sh` |
| **CI runs `make ci` unchanged, plus `make selftest` as its own job** | If CI needed its own variant of the gate, the local one would stop being the truth within a month. The self-test needs no stack, so it reports first | §13 |

**Honest status: the OpenAPI spec records which operations exist, not what they accept.**
`nelmio/apidoc` extracts nothing from controllers that carry no attributes and no typed
request DTOs, so both the generated TypeScript types and the MCP tools' input schemas are
free-form. Inventing schemas in the MCP layer would mean clients validating against this
repository's guess rather than the server's rules. The fix belongs to the endpoints.

### v3.4 → v3.5 - what Phase 8 changed in the design

| Change | Why | Applied in |
|---|---|---|
| **DNS is checked against the AUTHORITATIVE nameserver**, not the local resolver | A stale ISP cache produces a false pass (record deleted an hour ago) or a false fail (record added a minute ago). Only the authoritative answer decides | §10.2 |
| **`dns-check` accepts `user@host` as its target** | The caller already typed the server once; asking for the IP again in a second format is how a preflight checks the wrong address | §10.2 |
| **`prod-check` - the production stack's invariants, asserted** | §8.2 wrote them as sentences nobody re-reads, and each would be false *silently*: a bind-mounted source makes `opcache.validate_timestamps=0` serve stale code, a published DB port puts Postgres on the internet | §8.2, §12.3 |
| **`shell-check` - ShellCheck at `-S warning` over every script** | This project is a lot of shell, and the shell is the part that runs against real servers with root | §12.3 |
| **A check that cannot run FAILS** | The first `check-shell.sh` treated "no output" as "clean", so a Docker error and a clean tree were indistinguishable - the same shape as the Phase 1 smoke test that called a dead API healthy. The self-test caught it | §12.3, [[status-codes-alone-are-not-a-health-check]] |
| **`selftest` restores everything its fixtures break** | It restored only `backend/src/Module` and `AGENTS.md`; the first compose fixture leaked into the next check and failed it for the wrong reason | §12.3 |
| **Two networks in production** (`edge`, `net`) | The database is not on the same segment as the thing holding port 443 open to the internet | §8.2 |
| **`LETSENCRYPT_EMAIL` and `LE_CA_SERVER`** | ACME registration fails without an address, at the point where nothing has a certificate yet. Staging points at Let's Encrypt's staging CA - production allows five failed authorisations per hour, then locks you out for a week | §11 |
| **`BACKUP_AGE_KEY_FILE` exists and is empty by default** | A backup an attacker holding the server can decrypt protects against disk failure and nothing else | §9.3 |
| **Backups include the `.env` and the JWT keys** as a second encrypted bundle | A database alone does not bring a dead server back: without those, every session breaks and every encrypted column is lost | §9.3 |
| **The nightly timer fires at 03:17 with jitter** | Every timer in the world fires on the hour, and a bucket that throttles at 03:00 throttles everybody at once | §9.3 |

**Honest status: none of these scripts has been run against a real VPS.** Their logic is
linted at `-S warning`, their argument handling and refusals are exercised, the DNS half is
verified against live authoritative nameservers, and `compose.prod.yml` is validated and
invariant-checked. The provisioning path itself - apt, the deploy user, sshd, ACME issuance -
is verified the first time somebody points it at a server. `.ai/platform/docs/deployment.md` says
so in the same words.

### v3.3 → v3.4 - what Phase 7 changed in the design

| Change | Why | Applied in |
|---|---|---|
| **Mail is routed to the `jobs` transport** | `IdentityMailer` documented itself as queued and nothing routed `SendEmailMessage`, so every registration waited on SMTP | §6.8 |
| **`search.php` per module, with a required permission** | §6.9 named the file and nothing read it. The permission is required because search returns an *excerpt* - content - so a hit the caller may not read is a disclosure | §6.9, `ModuleDeclarations` |
| **`SearchIndexerInterface` takes the tenant on writes** | It read the ambient scope, which turned every legitimate `runUnscoped()` write into a hard failure. Reads still fail closed | §6.9, [[an-ambient-dependency-breaks-its-legitimate-callers]] |
| **`/api/search` lives in the kernel** | Results span modules by definition; a Search module would have to know about all of them | §6.9 |
| **Delivery failures are recorded by a listener, not the handler** | Handlers run inside `doctrine_transaction`: a row written before a throw is rolled back with it, and the log read `attempts: 0` through five real retries | §6.8, [[a-handler-cannot-record-its-own-failure]] |
| **`JobInterface` + `WebhookDeliveryFailed` live in `Message/` and `Service/`** | Module directories are a fixed set and none is `Exception/`; adding one would change the convention for every module | §6.3 |
| **Notification types are declared, like flags** | An undeclared type has no title key, so its row would be permanently unreadable. Same registry pattern, same reason | §6.9 |
| **The kernel owns `NotifierInterface`** | So raising a notification depends on the kernel, not on the Notification module - as `TenantSetupInterface` already does | §6.9 |
| **The API and workers trust the dev CA** | A webhook pointed at this machine failed at the TLS handshake, five retries deep, with an error naming a certificate rather than the missing trust | §8.1 |
| **A `scheduler` container, and a `webhook-echo` receiver under the `e2e` profile** | Two example scheduled tasks the repositories already had queries for and nothing called; and a real receiver, so a signature can be verified live by an independent implementation | §8.1, §14 |
| **`Example` budget 800 → 850** | Search became a fifteenth platform service the reference module must demonstrate. The specification grew, not the module's waste | §12.1 |
| **`symfony/http-client` moved to `require`** | Outbound delivery is the Webhook module's entire purpose; it was a dev dependency | §3 |

Not done, and not deferred silently: **Sentry's SDK is not installed.** `SENTRY_DSN` flows
to all four apps and `.ai/platform/docs/observability.md` documents the one-command opt-in, but
shipping a vendor client nobody configured adds weight to every project built from this
repository - and adding a production dependency is an `Ask First`. OpenTelemetry is
explicitly optional in this phase and stays off. The Manager module's scheduler view still
shows queue depth and failures rather than per-task last-run/next-run, which needs a run
table nothing yet writes.

### v3.2 → v3.3 - what Phase 6 changed in the design

| Change | Why | Applied in |
|---|---|---|
| **`Demo` deleted; `Example` is the one reference** | Two near-identical reference modules means an agent reads whichever it finds first. `Demo` was 2a's proof that discovery works and 2b's proof that the contracts work; it had served both | §6.7, §12.1 |
| **Flags can be declared** - `FlagProviderInterface` + `FlagDefinition` in the kernel, `app:flags:sync` in Settings, `make flags` in `builddev` | Phase 4 shipped the resolution chain with **no way to create a `Setting`**: the operator console listed nothing and no flag could ever be switched on. No test noticed, because the one test that needed a row wrote it itself | §6.9, ADR-0022 neighbours, [[a-mechanism-with-no-way-in-is-untested-by-construction]] |
| **`JobInterface` + one routing line** | The kernel documented "work that belongs in the background is a message on the jobs transport" and nothing demonstrated it. One line in `messenger.yaml` now routes every module's jobs, exactly as `DomainEvent` is routed to the outbox | §6.8, §6.9 |
| **`app:tenant:seed` gains nothing; `app:user:create --seed` does the work** | unchanged from v3.2 | - |
| **Module LOC budgets are enforced** | §12.1 specified 2,500/40 per module and 800 for `Example`, and nothing measured either. `make agents-budget` now does, excluding `Migrations/` - an append-only ledger nobody reads to understand a module | §12.1 |
| **`Example` measures 800/800** | The budget was set before the module existed. It is now measured: one of every platform concern, written in this codebase's commenting style, is exactly 800 code lines. The next demonstration added to it must replace one | §12.1 |
| **Repositories are not `final`** | PHPUnit cannot double a final class, so a final repository is one no handler can be unit-tested against | §6.4 |
| **The archive route answers 202** | It accepts work rather than doing it; the job id is what the browser watches | §6.7 |

Not done, and not deferred silently: §14 gives Phase 6 "PHP dead-code check promoted from
advisory to blocking if deterministic through Phases 3–6". There is no dead-code check to
promote - §13 lists it inside `make arch` but it was never built. It belongs with the other
container-introspection checks in Phase 9, which is where the remaining ones live.

### v3.1 → v3.2 - what Phase 5 changed in the design

Every row is a decision the plan did not anticipate, made because building the thing
surfaced something the design had not.

| Change | Why | Applied in |
|---|---|---|
| **CORS is an exact-origin allowlist naming `APP_URL`/`MANAGER_URL`** | There was no CORS configuration at all, and 100 passing tests could not see it: CORS is enforced by the browser, and neither `KernelBrowser` nor `curl` sends an `Origin`. The subdomain layout makes every call cross-origin, so this was load-bearing from the first browser | ADR-0022, §13 security list, `tests/Security/CrossOriginPolicyTest.php` |
| **`GET`/`PATCH /api/profile`, and one session shape everywhere** | The settings screen needed a profile endpoint, and login / refresh / switch-tenant / impersonation each returned a slightly different object. They now all go through `SessionPayload`, and it carries the effective permission list | §7.2, `Identity` MODULE.md |
| **Permission rules moved to `PermissionResolver`** | The voter enforces them and the session payload advertises them. Two copies drift in the worst direction - a button that renders and then 403s | §6.6 |
| **`app:user:create --seed`** | Signup leaves an account unverified by design, which is right for the public form and wrong for a fresh server, a developer thirty seconds after `builddev`, and the e2e suite | §9, `Identity` MODULE.md |
| **`app:tenant:seed` enters the tenant scope** | Setup hooks write tenant-scoped rows, so without a scope every "have I already done this?" read returned nothing and re-seeding silently duplicated the examples | §6.9 tenant setup |
| **Rate limits are raised in `dev`, not in `test` only** | Every developer, every tab and the whole browser suite arrive from one address; the production budgets applied there limit the developer rather than an attacker. Raised, never removed | §13 |
| **`make typecheck` and `make e2e` exist and are in `ci`** | §13 listed both as gates with no implementation behind them | §13, `Makefile` |
| **Smoke test asserts an `app-role` marker** | Its old expectations were phrases from a placeholder banner. The marker is in the HTML shell (so it works for the two SPAs), names *which* app answered (so three hosts pointing at one container fails), and is a role rather than the project name (which `make init` rewrites) | §12.3, `scripts/dev/smoke.sh` |
| **Frontend catalogues live at `<layer>/i18n/locales/` with a `.ts` re-export** | Where `@nuxtjs/i18n` looks; the `.ts` keeps Vite's json plugin off the module's own transform, which otherwise breaks server rendering | §7.1, `check-i18n.sh` |
| **Apps pin `srcDir`; module layers set no paths** | `extends` merges a layer's config into the app, so a module's `srcDir` became the app's and the router silently matched nothing | §7.2, [[a-layers-config-is-the-apps-config]] |
| **`ssr: false` for `frontend` and `manager`** | The token is in memory and the refresh cookie is host-scoped to the API, so a server render has no session to render with | §7.2, §7.3 |

Three lessons recorded: [[a-test-client-never-asks-for-permission]],
[[a-layers-config-is-the-apps-config]], [[a-no-op-write-does-not-bump-the-version]].

### v3 → v3.1 - all open questions closed

| Decision | Applied in |
|---|---|
| `ui-kit/` stays top-level | §2 |
| Cloudflare-only apply script | §2 |
| Manual deploy-key rotation | §2, §10.3 |
| Markdown landing | §2, §7.4 |
| `buildprod` refuses without `BACKUP_REMOTE`; staging warns | §2, §9.1 step 11, §9.3, Phase 8 acceptance |
| Hierarchical orgs wait for demand | §2 |
| Kernel publishing per-project, later | §2 |
| Phase 2 → 2a (module system + guardrails) / 2b (platform contracts) | §2, §14 |
| §16 rewritten as a resolved-decisions register | §16 |

### v2 → v3 - platform services from the gap analysis

Applied "sooner is better": every contract lands in Phase 2, every module by Phase 7.

| Tier | Item | Applied in |
|---|---|---|
| 1 | Attachments / storage | §3, §6.9 `StorageInterface`, `Attachment` module (§6.7), Phase 2 contract + Phase 3 module |
| 1 | API keys | §6.6 third principal, `ApiKey` module, Phase 3 |
| 1 | i18n (`pl` + `en`) | §2, §6.4 rule, §6.9, §7.1, `i18n-check` (§12.3, §13), Phase 2 wiring + Phase 5 UI |
| 1 | Optimistic locking, default on | §6.4 rule, §6.9 `VersionedInterface` + 409, `useConflict` (§7.1), Phase 2 |
| 1 | Settings + feature flags | `Settings` module (§6.7), `#[Flag]`, `useFlags`, Phase 4 |
| 1 | Extension surfaces, listed | **§6.10** + `.ai/platform/docs/extension-surfaces.md`, Phase 2 |
| 2 | Command bus with audit snapshots | §2, §6.4 rule, §6.9, Audit rewired (§6.7), Phase 2 |
| 2 | Tag-based cache contract | §2, §6.4 rule, §6.9 `TenantCache`, Phase 2 |
| 2 | Webhooks, outbound | `Webhook` module (§6.7), §6.9, Phase 7 |
| 2 | Structured logging + request correlation | §3, §6.9, `RequestIdStamp`, Phase 2 |
| 2 | GDPR export + erase | §6.6, §6.9 `GdprSubjectInterface`, arch rule, Phase 2 contract + Phase 3 commands |
| 2 | Progress for long jobs | `Progress` module, `useProgress`, Phase 2 contract + Phase 3 module |
| 2 | Field-level encryption, light | §2, §6.6, `#[Encrypted]`, HKDF DEKs, `*_hash`, Phase 3 |
| 2 | Playwright E2E | §3, §4 `e2e/`, §13, Phase 5 |
| 2 | Tenant setup hooks | §6.9 `TenantSetupInterface`, `Tenant` module runs them, Phase 2 + 3 |
| 2 | Typed events + browser bridge | §6.9 `#[ClientBroadcast]`, `useAppEvent`, Phase 2 + 5 |
| 3 | MCP server from `openapi.json` + ACL | Phase 9 |
| 3 | Search contract (tsvector) | §2, §6.9, Phase 2 contract + Phase 7 endpoint |
| 3 | Scheduler status view | `Manager` module, Phase 4 API + Phase 5 UI |
| - | Kernel as internal package (`open-enu/kernel`, `@open-enu/ui-kit`) | §1 goal 8, §2, §4, §6.1, Phase 0 |
| - | Not adopted, as ADRs: custom fields (0013), hierarchical orgs (0014), undo (0015) | §1 non-goals, §2, §6.5 N-column filter |
| - | `Example` budget 600 → 800 LOC | §6.7, §12.1 - one line per platform service |

### v1 → v2 - architecture/security review

Applied from the architecture/security review. Numbers refer to the review's findings.

**Critical**

| # | Finding | Applied in |
|---|---|---|
| 1 | Realm isolation was firewall-order only; shared email crosses realms | §2, §6.6 `aud` + `AudienceListener`; Phase 4 acceptance test |
| 2 | Tenant filter didn't fail closed; native SQL / `getReference()` / identity-map bypasses | §6.4, §6.5 (`1 = 0`, `#[Unscoped]`, EM clear on `runUnscoped()` exit); §12.3 checks |
| 3 | Impersonation had no design | §6.6 impersonation spec, `ImpersonationGuard`, `#[DeniedUnderImpersonation]`, banner; Phase 4 |
| 4 | Token storage unspecified (implied `localStorage`); Mercure topics unauthorized | §2, §6.5 (topic scoping + `mercureAuthorization` cookie), §6.6 token-transport table, §7.1 |
| 5 | Domain events had no transactional semantics | §2, §6.8 outbox on Doctrine transport, `failed` on Doctrine, two workers in §8.1 |
| 6 | Same-disk `pg_dump` isn't a backup | §9.3 off-box encrypted backups, `backup-verify`, Phase 8 acceptance |
| 7 | SSH hardening could lock the operator out | §9.1 step 3 lockout-safe ordering |

**Design gaps**

| # | Finding | Applied in |
|---|---|---|
| 8 | User↔tenant cardinality undecided | §2, §6.5 `Membership` + `switch-tenant` |
| 9 | Cross-module DB FKs unaddressed | §1, §6.4 rule + migration linter |
| 10 | Frontend page re-exports fight Nuxt routing | §2, §7.2 modules as local Nuxt layers |
| 11 | `APP_ENV=staging` breaks `when@prod` | §2, §8.2 `APP_ENV=prod` + `APP_STAGE` |
| 12 | Audit log missing | §6.1, §6.7 `Audit` module + `AuditLoggerInterface` |
| 13 | Project rename left as an open question | §1 goal 6, §2, §9.1 `init`, Phase 0 |
| 14 | OpenAPI "optional" but relied upon | §2, §3, §6.3, §7.1 generated types, §12.3 freshness check |

**Thesis consistency**

| # | Finding | Applied in |
|---|---|---|
| 15 | Guardrails arrived in Phase 9 | §14: moved to Phase 2; Phase 9 keeps only checks needing real code |
| 16 | `Example` couldn't be 600 LOC with its scope | §6.7 two-tier reference: minimal `Example`, `Identity` as full reference |
| 17 | Duplicate-signature detection overstated | §12.3 downgraded to advisory similarity hint |
| 18 | Route-test coverage had no mechanism | §12.3 runtime route tracing + `#[NoTestRequired]` |
| 19 | Unreferenced-file check hand-waved for autowired PHP | §12.3 container introspection (advisory until proven) + `knip` for TS |
| 20 | Task Router written at the end | §12.1/§12.2 grown per phase; `make module` adds the row |
| 21 | No fast inner loop | §12.5 `make check` < 60 s vs `make ci` |

**Usability / environment / ops**

| # | Finding | Applied in |
|---|---|---|
| 22 | WSL2 ignored (Windows hosts + trust store) | §8.1, §9.1 `builddev`, Phase 1 acceptance |
| 23 | "Rolling restart" on one replica is downtime | §1 non-goals, §8.3 stated honestly |
| 24 | `:latest` images in prod | §3 pinned + Dependabot docker |
| 25 | Registration without verification; rate limits too narrow | §6.6 email verification + purge job + rate-limit table |
| 26 | Hardcoded GitHub fingerprint | §10.3 fetched from `api.github.com/meta` |
| 27 | Reset/invite tokens unspecified | §6.6 single-use, hashed, expiring |
| 28 | No observability hook, no deep health | §3 Sentry no-op, §8.2 `/health` + `/health/deep` |
| 29 | `.claude/skills/` single-vendor | §4 `.ai/platform/skills/` canonical + symlinks |
