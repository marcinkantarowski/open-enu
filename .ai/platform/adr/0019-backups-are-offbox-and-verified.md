# ADR-0019 - Backups are off-box, encrypted, and restore-tested

**Status:** accepted · **Date:** 2026-09-10

## Context
A nightly `pg_dump` onto the same disk as the database is the default advice and is not a
backup: one disk failure takes both. A backup that has never been restored is equally a
hope rather than a guarantee.

## Decision
Nightly: `pg_dump -Fc` → `age` encryption → `rclone` to any S3-compatible remote, with
7 daily / 4 weekly / 3 monthly retention. A second bundle carries `.env`, JWT keys and ACME
storage, so a dead server restores onto a new one. `make backup-verify` restores the latest
remote dump into a scratch container and validates it - part of Phase 8's acceptance.

**`make buildprod` refuses to complete without a reachable `BACKUP_REMOTE`.** Staging warns
nightly instead.

## Consequences
- Production cannot be stood up one command away from having no backups.
- Requires `rclone` and `age` on the server, and a bucket - checked by preflight.
- The age private key is the operator's responsibility and must not live on the server.
