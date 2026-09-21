# OpenEnu

A single-repo foundation for starting new products: **Symfony 7.4 API + three Nuxt 4 apps +
Docker/Traefik**, with one command for local development and one for provisioning a fresh
staging or production server over SSH.

It is designed to be **read and extended by AI agents without creating technical debt** -
paths are derivable rather than discovered, and every convention that matters ships with a
check that fails when it is broken.

Full design: **[.ai/platform/PLAN.md](.ai/platform/PLAN.md)** · Agent contract: **[AGENTS.md](AGENTS.md)** ·
Decisions: **[.ai/platform/adr/](.ai/platform/adr/)**

---

## Status - all nine phases built

`make builddev` goes from a fresh checkout to nine hosts answering over trusted HTTPS in
about 90 seconds, on Linux, macOS or WSL2 - and what answers is now an application.

**Working end to end:** eleven backend modules; registration, email verification, login,
password reset, tenant switching and scoped API keys; fail-closed tenant isolation proved
from four directions; every write through a command bus that records a before and an after;
transparent field encryption; optimistic locking that answers a stale write with both
versions and the saved record; tenant-scoped realtime; an operator console with cross-tenant
audit, per-tenant kill switches and time-boxed impersonation.

**The three apps are real.** `frontend` and `manager` are SPAs - the access token lives in
memory, so there is no session to render on a server - and `landing` renders on the server
from markdown in both English and Polish. Each feature is one Nuxt layer, discovered from
its directory, contributing its own pages, menu entries and translations with nothing
central edited.

**There is one module to copy.** `Example` owns a single entity carrying exactly one of
every platform concern - scoping, locking, encryption, JSONB attributes, a command and
handler, a broadcast event, a record-level voter, a feature flag, a background job with a
progress bar, an impersonation guard, a tenant seed, searchable fields and deterministic
fixtures - in under 850 lines including its tests. That number is the argument: a
cross-cutting concern here costs about a line.

Twelve Playwright specs drive a real browser against the real stack: cookie login, session
restore, tenant switch, impersonation handoff, upload, the conflict bar, a long job watched
to completion, an operator flipping a kill switch - and a live update arriving for one
workspace while another workspace's open stream receives nothing.

**Events leave the system.** A tenant registers a webhook endpoint, subscribes it to event
names, and gets signed deliveries with five retries and a log of every attempt - verified
end to end against a real receiver, with the signature checked by an independent
implementation. Notifications are a declared type registry plus a per-user feed that stores
translation keys rather than sentences, so the same row reads correctly in either language.
Full-text search is one endpoint across every module that opted in, with a permission per
entity type. Two example tasks run on a clock.

**A bare VPS becomes a running product in one command.** `make buildprod` starts with a
ten-second, read-only preflight - DNS checked against the authoritative nameserver, CAA,
ports 80 and 443, SSH, server sanity, and whether the *server* (not your laptop) can read the
repository - then installs Docker, creates the deploy user in an order that cannot lock you
out, generates secrets it will never regenerate, builds, backs up, migrates, waits for a deep
health check, and installs systemd units so a 4am kernel update is not an outage. When DNS is
wrong it writes the fix as a BIND zone file, a Cloudflare delta script and a JSON document -
because a diagnosis you have to retype into a control panel is half a tool.

It refuses to call itself finished on a production box with no off-box backup.

**And an agent can read it and drive it.** `.ai/inventory.json` is a committed index of
every declared name, so *does this already exist?* is a grep rather than an exploration.
Every endpoint is proved to have a functional test by runtime route tracing - the check
records which routes actually ran, so it notices a deleted test, not just a missing name.
Unused TypeScript fails the build. And `app:mcp:serve` offers the API to an MCP client as
tools generated from the committed spec, the router and the ACL, filtered to what a scoped
API key can actually call and authenticated as one:

```bash
make console CMD="app:apikey:create --tenant=acme --name=agent --permission=example.view"
make console CMD="app:mcp:serve --list"
```

Honest gap: the OpenAPI spec records which operations exist, not yet what they accept, so
generated types and MCP input schemas are free-form. See
[`.ai/platform/docs/agent-interfaces.md`](.ai/platform/docs/agent-interfaces.md).

```bash
make builddev      # the whole stack, from nothing, locally
make check         # the inner loop - run after every edit (~5s)
make ci            # everything: guardrails, tests, types, browser suite
make smoke         # every host answers, with the right body
make module NAME=X # a feature, scaffolded across backend, frontend and docs
make inventory     # regenerate .ai/inventory.json - what already exists
make selftest      # prove every guardrail still fails when broken
make help          # every target, grouped
```

Going to a server:

```bash
make preflight  HOST=deploy@203.0.113.42 DOMAIN=example.com REPO=acme/example
make deploy-key HOST=deploy@203.0.113.42 REPO=acme/example
make buildprod  HOST=deploy@203.0.113.42 DOMAIN=example.com REPO=acme/example
```

Read [`.ai/platform/docs/deployment.md`](.ai/platform/docs/deployment.md) first - in particular, the scripts
have not yet been run end to end against a real VPS.

### Signing in for the first time

Registration deliberately leaves an account unusable until its address is verified, which is
right for the public form and unhelpful on a machine you just set up:

```bash
make console CMD="app:user:create you@example.com --tenant='Acme' --role=owner --seed"
make console CMD="app:manager:create ops@example.com"   # the operator console
```

```
https://open-enu.local             landing
https://app.open-enu.local         tenant application
https://manager.open-enu.local     operator console
https://api.open-enu.local/health  API
https://api.open-enu.local/.well-known/mercure  realtime hub
https://traefik.open-enu.local     Traefik dashboard (dev only)
https://mail.open-enu.local        Mailpit - every outbound mail lands here
```

## Starting a project from this

```bash
make init NAME=myproject DOMAIN=myproject.com
make env
```

`init` renames the application - compose project, containers, database, titles, docs, default
domain - and is idempotent.

It deliberately does **not** rename `open-enu/kernel`, `OpenEnu\Kernel\` or
`@open-enu/ui-kit`. Those are the framework's identity, not the project's, which is what
lets a project later pin a kernel version and pull framework fixes instead of hand-merging
them. That invariant is the most important one here, so it is guarded by `make selftest` -
and the guard has been verified to fail when the protection is removed.
See [ADR-0016](.ai/platform/adr/0016-kernel-packaging.md).

## Changing it

`main` is protected: nothing is pushed to it directly, nothing is force-pushed, and nothing
merges until CI is green on a branch that is up to date with it.

```bash
git switch -c my-change
make check                  # after every edit
make ci                     # before opening the pull request - the same gate CI runs
git push -u origin my-change   # then open a pull request
```

A pull request runs two required checks in GitHub Actions
([`.github/workflows/ci.yml`](.github/workflows/ci.yml)):

- **`make selftest`** - about twenty seconds: breaks every guardrail in a throwaway copy
  and proves each one fails.
- **`make ci`** - about seven minutes from a cold cache: brings the whole stack up from
  nothing on a clean runner, then guardrails, PHP suites, smoke, type-check and the browser
  suite. Image layers are cached (`docker/compose.ci.yml`), so a run whose Dockerfiles did
  not change skips most of the build.

A push to `main` does not run the gate again - the merged tree is the one the pull request
tested. It only rebuilds the images to refresh that cache. A pull request from a fork waits
for a maintainer to approve its run, and the workflow's token can read the repository and
nothing else.

Dependabot opens grouped updates once a month per ecosystem - composer, npm, Docker images
and GitHub Actions - and security fixes as soon as an advisory lands. Each is an ordinary
pull request through the same gate.

An AI agent working in this repository edits, runs the checks and stops there: commits,
pushes and merges are made by a person ([AGENTS.md](AGENTS.md)).

## Layout

```
backend/kernel/   open-enu/kernel - the framework. Versioned, never renamed.
backend/src/      App\ - application modules, one directory per feature.
ui-kit/           @open-enu/ui-kit - Nuxt layer shared by the three apps.
frontend/         app.${DOMAIN}       tenant users
manager/          manager.${DOMAIN}   platform operators (separate bundle + firewall)
landing/          ${DOMAIN}           marketing site
e2e/              Playwright - what functional tests cannot see
docker/           compose files, Traefik, Postgres init
scripts/          lib/ shared shell · dev/ local · remote/ provisioning
.ai/              this project's ADRs, specs, lessons, analyses - never touched by an update
.ai/platform/     the platform's docs, specs, ADRs, lessons, skills - replaced by an update
```

Which side a record belongs to is decided by `.project.json`, not by judgement - see
[`.ai/README.md`](.ai/README.md) and
[ADR-0023](.ai/platform/adr/0023-platform-and-project-knowledge-are-separate-trees.md).

## Configuration

`.env` is the only file edited by hand. Every per-app env file is **derived** from it by
`make env`, so hostnames, CORS origins and Mercure URLs cannot drift apart - the API's
allowed origins *are* the URLs the apps are served at, named by the same two variables.

Two invariants: an existing value is never overwritten (this is what makes redeploying
safe), and a variable added upstream surfaces as a message rather than a 3am crash.

Everything hangs off one `DOMAIN`: the apex serves the landing page, with `app.`, `manager.`
and `api.` beside it (Mercure is served from `api.`). Changing domains is one line.

## Requirements

Docker with Compose v2, git, openssl, mkcert. To deploy you also need `dig`, `rclone`, `age`
and optionally `gh` - `make check-tools` tells you which you are missing and why each matters.

Linux, macOS and **WSL2** are supported. On WSL2 the browser and certificate trust store live
on Windows, so `make builddev` reaches across that boundary for the hosts file and the CA;
`make check-tools` verifies both.

## License

MIT - see [LICENSE](LICENSE).
