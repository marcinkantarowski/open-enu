# Staging from images: a rehearsal of production, as an option

**Status:** accepted · **Date:** 2026-11-23

## Problem

Staging is the dev stack on a server (2026-09-17-staging-and-promotion.md), so an agent can
edit code there and see it hot-reload. A team that codes locally and only ever pushes pays
for that and gets nothing back:

- **It does not rehearse production.** Nuxt dev servers, Symfony in `APP_ENV=dev` with the
  profiler, source bind-mounted. A failure that only exists in a production build (a build
  error, `when@prod` configuration, opcache, the prod Dockerfile itself) passes staging and
  surfaces in production. ADR-0011 already says staging should run `APP_ENV=prod`.
- **It does not fit the box.** Three `nuxt dev` servers with no memory limit filled a 4 GB
  staging server until the kernel spent its time reclaiming memory and nothing answered,
  VPN and edge included. A dev server's memory grows with every page compiled and every
  hot-reloaded edit; a production build serves the landing in ~50 MB.

## Approach

`STAGING_MODE` in the staging checkout's `.env`: `dev` (the default - nothing changes for
an existing staging) or `images`.

**`images` runs production's compose file plus `compose.staging-images.yml`**
(`stage_compose_files`): the same images, `APP_ENV=prod`, `APP_DEBUG=0`. The override only
adds what makes it staging:

- every router behind `${STACK}-allow@file,noindex@file` instead of the rate limit - the
  allowlist is the access control, and a team behind one VPN address is one client;
- Mailpit, so no mail from a rehearsal reaches a real person (`MAILER_DSN` keeps its
  `smtp://mailpit:1025` default; production refuses Mailpit by `prod-check`, not by code).

`provision.sh` takes it as `STAGING_MODE=images make buildstaging …` (like `ALLOW`), writes it
to `.env` and derives `COMPOSE_FILE`, `APP_ENV` and `APP_DEBUG` from it. A value already in the
server's `.env` is kept when none is given.

**`release.sh` follows the compose files, not the stage name.** Production and image staging
share one path - build `--pull`, migrate in a one-off container, `up`, warm the cache - and
differ only where staging must: no pre-deploy dump (staging data is disposable) and the
health check inside the stack (the allowlist refuses the server's own address).

**`promote` runs the gate where the tools are.** An image has no dev dependencies, so
`make promote-gate` cannot run on image staging. Run from a machine whose checkout is
clean and at the very commit staging runs, `promote` runs the gate there; otherwise it
refuses and says how. On dev staging nothing changes.

**`prod-check` holds image staging to staging's rules**, rendered with the project's own
files: no published port, every router behind the allowlist with a public certificate, and
no bind-mounted source - which, unlike dev staging, it now can promise.

## Rejected

- **Making `images` the default.** Existing stagings would change under their owners at the
  next `platform-update`. A default is changed by a decision, not by a deploy.
- **A third stage (`rehearsal`) beside staging.** Two staging stacks on one box need the RAM
  this exists to save; the choice is per project, not per box.
- **Running the gate inside the production image.** It would need the dev dependencies in an
  image that ships, or a second image built only for the gate.

## Consequences

- An image staging shows a change after `make deploystaging` rebuilds its images (minutes),
  not on save. Code locally; staging is where a commit is looked at before it is promoted.
- Switching an existing staging: set `STAGING_MODE=images` in its `.env` and re-run
  `make buildstaging` (or set `COMPOSE_FILE`, `APP_ENV=prod`, `APP_DEBUG=0` by hand and
  `make deploystaging`). The database volume is the same (`<stack>_pgdata`). Uploaded files are
  not: dev keeps them in `apivar`, production in `storage` - copy them if they matter.
- `make promote` from image staging needs the local dev stack for its gate.

## Found on the first run

The first image staging was the first time the production API image was ever built, and it
did not build: `php.prod.ini` named `opcache.preload` from the Symfony cache, which does not
exist until the server warms it - and PHP with a missing preload file does not start, so
`composer install` in the Dockerfile failed. Behind it, the cache lived on the `apivar`
volume, shared by every release: production never checks a compiled container is current,
so a second release would have booted on the first one's.

Now each container warms its own cache in `entrypoint.sh` and switches preload on once the
file exists; production mounts only `/app/var/storage`. `prod-check` fails on `/app/var` or
its cache on a volume.
