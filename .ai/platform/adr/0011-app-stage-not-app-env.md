# ADR-0011 - Staging is `APP_ENV=prod` + `APP_STAGE=staging`

**Status:** accepted · **Date:** 2026-09-10

## Context
`APP_ENV=staging` is the obvious move and quietly breaks Symfony: every `when@prod`
configuration block stops applying, so staging runs a configuration that exists nowhere
else - which is the one thing staging must not do.

## Decision
Staging runs `APP_ENV=prod`. A separate `APP_STAGE` (`local` | `staging` | `prod`) drives
behaviour: `noindex` headers, demo seeding, Let's Encrypt staging certs, Sentry environment
tag. Symfony configuration never branches on `APP_STAGE`.

## Consequences
- Staging exercises the production configuration, which is the point of staging.
- Stage-dependent behaviour must be explicit application logic, not config inheritance.
