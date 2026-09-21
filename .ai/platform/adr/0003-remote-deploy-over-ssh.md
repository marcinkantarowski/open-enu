# ADR-0003 - Deploy by SSH + git pull, building on the server

**Status:** accepted · **Date:** 2026-09-10

## Context
A new project has a domain and a bare VPS, and frequently no container registry yet. A
registry-first pipeline blocks the first deploy on infrastructure that does not exist.

## Decision
`make buildprod HOST=… DOMAIN=… REPO=…` SSHes in, installs Docker if missing, pulls the
repo with a read-only deploy key, generates missing secrets, builds images **on the
server**, migrates and health-gates. `SYNC=rsync` pushes the working tree instead, for day
one when there is no git remote.

## Consequences
- One command from bare VPS to live, with no prerequisites beyond SSH and DNS.
- Server-side builds are slower and need disk; acceptable on a single host.
- A registry-based path stays possible later without changing the compose files.
