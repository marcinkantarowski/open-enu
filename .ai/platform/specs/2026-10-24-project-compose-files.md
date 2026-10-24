# A project's own compose files

## Problem

Every compose file is the platform's: `compose.dev.yml`, `compose.prod.yml`,
`compose.staging.yml`, `compose.ci.yml`, all brought up to date by `make platform-update`. A
project that needs a service or a route of its own - a public address kept from a system it
replaced, a host the platform does not know - has nowhere to write it. Edited into a platform
file, it is a conflict at the next update; left out, it does not exist.

## Approach

- `docker/project/` belongs to the project. The platform never ships it, so an update never
  touches it.
- For every file in `COMPOSE_FILE`, `scripts/lib/compose.sh` adds the project's file **of the
  same name** right after it, when there is one: `compose.prod.yml`, then
  `docker/project/compose.prod.yml`. The `Makefile`'s `COMPOSE` does the same. Each stage keeps
  its own rules: a production router names its certificate resolver, a staging router goes
  behind the allowlist in `docker/project/compose.staging.yml`, as the platform's do.
- `prod-check` renders the stacks with the project's files in, so a project router answers to
  the same invariants as the platform's. `selftest` proves both: a project production router
  without a resolver, and a project staging router outside the allowlist, fail the check.

## Rejected

- **One project file for every stage.** Production and dev routers differ (a resolver against
  `tls=true`), and staging must add the allowlist; one file cannot say all three.
- **Adding the files to `COMPOSE_FILE` in `.env`.** Every existing `.env` would need editing by
  hand, and `provision.sh` writes the value on a server; a missing file named there stops
  compose altogether.
- **A dynamic Traefik file per project.** Routes only; a project service could not be added,
  and the edge's files are written by `edge.sh`, not by the project.

## Known gap

A bare `docker compose` typed in the checkout reads `COMPOSE_FILE` alone and does not see
`docker/project/`. Scripts and make targets do.

## Changelog

- 2026-10-24 - first version, for a project's routes kept from the system it replaced.
