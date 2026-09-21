# Staging and production on one server or two, and promoting between them

**Status:** accepted · **Date:** 2026-09-17

## Problem

Two stages could not coexist on one server. Both resolved to `/opt/<slug>`, one compose
project, one `pgdata` volume and one set of systemd units: `buildstaging` against a production
box rewrote production's `.env` and would have run staging on production's database.

And there was no path from what you had looked at to production. `deployprod` pulled a
branch head, which may have moved since anyone saw it.

The workflow wanted: **vibe code on staging** - which has Mailpit and the rest of the dev
tooling - or locally in the dev stack, whichever suits the change; then ship to production
exactly what staging runs. Staging and production may share a server or not.

## Approach

**Every name carries the stage** (`scripts/lib/stage.sh`): `<slug>-staging` and `<slug>-prod`
for the checkout under `/opt`, the compose project, every container, network, volume and
router, and the systemd units. `STACK` and `COMPOSE_FILE` in each checkout's `.env` are the
only switches; every script reads them through `scripts/lib/compose.sh`.

**Staging is the dev stack, guarded.** `compose.dev.yml` + `compose.staging.yml`: hot reload,
Mailpit, profiler - so Claude Code can run in `/opt/<slug>-staging` and every edit is live.
The override unpublishes every port (Docker bypasses ufw) and puts every router behind an IP
allowlist from `STAGING_ALLOW_FROM`, plus `noindex`. `prod-check` fails if either is undone, or
if any script names `compose.dev.yml` itself and so would drop the override on the server.

**The edge serves servers too.** Production had its own Traefik, which cannot coexist with
staging's. `edge.sh` gains a server mode (by `APP_STAGE`): Let's Encrypt, the security-header
and rate-limit middlewares, no dashboard, state in `/opt/enu-edge`. Its static config is
*rendered* - the old `traefik.prod.yml` wrote `${LETSENCRYPT_EMAIL}` in a file Traefik does not
interpolate, so no certificate could ever have issued.

**Promotion is by SHA, through GitHub.** `make promote`: staging clean → not behind origin →
push staging's commits (its deploy key writes; production's stays read-only) → `promote-gate`
on staging → production checks out that SHA → `release.sh` → tag `prod-<timestamp>`.
`on HOST|local` lets the same script run from the staging server or from a laptop, with the
stages on one box or two.

**One release path.** `scripts/remote/release.sh` runs on the server after the caller moves the
checkout; provision, deploy and promote all end in it.

## Rejected

- **Basic auth on staging.** The apps call `api.` and `mercure.` cross-origin; a CORS preflight
  never carries credentials, so the API would fail every request.
- **Staging as prod-like images, a third `dev.` stack for coding.** Three stacks on one box
  needs 6 GB+, and a rehearsal nobody codes on drifts from the one people do.
- **Promoting from the staging checkout directly (no GitHub).** Loses history, review and the
  code itself with the server.
- **Let's Encrypt's staging CA for the staging stage.** Its certificates are untrusted by
  browsers, and staging is used in one.

## Consequences

- One server running both stages needs about 4 GB of RAM.
- A write-enabled deploy key lives on the staging server. `promote` from staging to production
  on *another* server also needs an SSH key to production there; promoting from a laptop does
  not.
- `release.sh` and `promote.sh` are linted and their refusals are reasoned, not run: nothing
  here has been executed against a real server. The edge in server mode was run live on spare
  ports (ACME rendering, allowlist 403/200, noindex, HSTS, redirect).
