# OpenEnu rename, and one edge proxy for every project on a host

**Status:** accepted · **Date:** 2026-09-17

## Problem

Two, arriving together.

**The framework's name changes from Startenu to OpenEnu** - including the kernel. ADR-0016 says
the kernel's name never changes, and that stays true for *projects built from* this
repository. It does not bind the framework itself before its first commit, when nothing
depends on the old name yet. This is the cheapest moment the rename will ever have.

Doing it exposed a bug the old name was hiding: **`make init` broke the application it
renamed.** Framework identifiers that lived outside `backend/kernel/` contained the project
slug, so init rewrote them - `StartenuKernelBundle` in `bundles.php` became a class that does
not exist, the route loader type became one the kernel does not recognise, and two service
tags stopped matching their `#[AutowireIterator]`. `selftest` inspected `.project.json` and
never looked at any of them.

**Two projects cannot run on one Docker host.** Containers were already prefixed with
`PROJECT_SLUG`, but each stack started its own Traefik on host ports 80/443, so the second
`make up` failed on a port that was already taken. And each Traefik read the whole Docker
socket, where router names (`api`, `frontend`, …) were the same in every project.

## Approach

### Naming

| What | Form | Renamed by `make init`? |
|---|---|---|
| Composer / npm package | `open-enu/kernel`, `@open-enu/ui-kit` | never - masked |
| PHP namespace, bundle | `OpenEnu\Kernel\`, `OpenEnuKernel*` | never - masked |
| Container parameters, service tags, loader types, cookies | `open_enu.*`, `open_enu_*` | never - contains neither slug nor name |
| PHPStan rule identifiers | `openEnu.*` | never - same reason |
| Project slug, name, domain, database | `open-enu`, `OpenEnu`, `open-enu.local`, `open_enu` | yes |

The rule that makes the table hold: **a framework identifier must not contain the project's
slug or studly name.** Underscored and camel-cased forms satisfy it by construction; the
remaining two prefixes are masked. `selftest` now counts every framework identifier before
and after init and fails if the count moves.

### The edge

One Traefik per host, not per project: `enu-edge`, started on demand by `make edge` (which
`make up` runs). Its name deliberately carries no project slug - it is shared, and `init`
must not rename it apart from its siblings.

- Each project writes its certificate and a dynamic-config file into the edge's state
  directory (`EDGE_HOME`, default `~/.local/share/enu-edge`); Traefik's file provider picks
  the certificate by SNI.
- The edge **joins each project's network** with that project's hostnames as aliases, so a
  container on `open-enu-net` still reaches `https://app.open-enu.local` the way a browser
  does. `make down` detaches it first.
- Every router and service name is prefixed with `PROJECT_SLUG`, and every routed container
  names the network Traefik must use.

Production initially kept its own Traefik. Superseded the same day by
[staging and promotion](2026-09-17-staging-and-promotion.md): the edge now has a server mode,
and staging and production share it on one box.

## Rejected

- **Per-project host ports.** Every URL gains a port, and the CORS origins, cookie domains and
  Mercure URLs derived from `DOMAIN` all have to learn about it.
- **An external shared network plus `extra_hosts: host-gateway`.** The traffic that reaches a
  container's own stack would leave Docker and come back through the host, where a firewall
  can drop it - the failure would look like a TLS or routing bug.
- **Keeping `startenu` in the kernel only.** A framework named differently from the product
  that ships it is a permanent footnote in every doc.

## Consequences

- The dev database, vendor and node_modules volumes are new (the compose project name
  changed). `make builddev` rebuilds them; development data does not carry over.
- `open-enu:field:v1`, the HKDF label for derived field keys, changed with the name. Nothing is
  deployed, so no ciphertext exists that it could orphan. **After a first deploy it must never
  change again.**
- `DB_PORT` is still published per project in dev; a second project on the same host sets its
  own.

## Verification

- `make selftest` - init leaves every framework identifier intact.
- `make check`, `make shell-check`, `make agents-budget`, `make dead-code`, `make typecheck`.
- `make builddev` + `make ci` once ports 80/443 are free on this machine.
