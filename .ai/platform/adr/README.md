# Architecture Decision Records

One file per decision, including the decisions to **not** do something - those are the ones
most likely to be mistaken for oversights and "fixed" by someone who did not read this
directory.

Read before proposing an architectural change. Add a new record rather than editing an
accepted one; supersede by reference.

| # | Decision |
|---|---|
| [0001](0001-modular-backend.md) | Modular backend over flat layers |
| [0002](0002-module-boundaries.md) | Module boundaries enforced by tooling |
| [0003](0003-remote-deploy-over-ssh.md) | Deploy by SSH + git pull, building on the server |
| [0004](0004-shared-db-tenancy.md) | Shared database, `tenant_id`, fail-closed filter |
| [0005](0005-membership-from-day-one.md) | `Membership(user, tenant, role)` from day one |
| [0006](0006-token-transport.md) | Access token in memory, refresh in an httpOnly cookie |
| [0007](0007-realm-isolation-by-audience.md) | Realm isolation by `aud` claim, not firewall order |
| [0008](0008-impersonation.md) | Impersonation is a specified crossing |
| [0009](0009-events-via-outbox.md) | Domain events through a transactional outbox |
| [0010](0010-frontend-modules-as-layers.md) | Each frontend module is a local Nuxt layer |
| [0011](0011-app-stage-not-app-env.md) | Staging is `APP_ENV=prod` + `APP_STAGE=staging` |
| [0012](0012-openapi-is-the-contract.md) | `openapi.json` generated, committed, diffed |
| **[0013](0013-no-custom-fields-layer.md)** | **No runtime custom-fields layer** |
| **[0014](0014-no-hierarchical-orgs-in-v1.md)** | **No hierarchical organizations in v1** |
| **[0015](0015-no-undo.md)** | **No undo/redo** |
| **[0016](0016-kernel-packaging.md)** | **The kernel is a package; never renamed** |
| [0017](0017-command-bus-for-writes.md) | Every write goes through a command bus |
| [0018](0018-light-encryption.md) | Derived per-tenant keys, not a KMS |
| [0019](0019-backups-are-offbox-and-verified.md) | Backups off-box, encrypted, restore-tested |
| [0020](0020-i18n-from-the-start.md) | Two locales from the start, enforced by lint |
| [0021](0021-search-tsvector-only.md) | Search interface with one Postgres driver |
| [0022](0022-exact-origin-cors.md) | CORS is an exact-origin allowlist, never a pattern |
| [0023](0023-platform-and-project-knowledge-are-separate-trees.md) | The platform's knowledge lives in `.ai/platform/`; the rest of `.ai/` is the project's |

**Bold** records explain a deliberate absence. If something seems missing, look there first.
