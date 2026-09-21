# ADR-0007 - Realm isolation by `aud` claim, not firewall order

**Status:** accepted · **Date:** 2026-09-10

## Context
Two Symfony firewalls with two user providers *look* isolated. They are not: both verify
against the same signing key, so a tenant token presented to `/api/manager` passes signature
verification and is then looked up by email in the manager provider. **A user whose email
matches a platform operator's authenticates as that operator.** The reference project has
this shape.

## Decision
Every token carries `aud` (`app` | `manager` | `api_key`). `AudienceListener` rejects any
token whose audience does not match the firewall that received it. The acceptance test uses
a deliberately shared email address.

## Consequences
- Realm crossing requires forging a signed claim, not guessing an email.
- Impersonation - the one legitimate crossing - must be explicit and is (ADR-0008).
