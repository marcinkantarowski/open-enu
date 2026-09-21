# ADR-0008 - Impersonation is a specified crossing, not a convenience

**Status:** accepted · **Date:** 2026-09-10

## Context
"Support can log in as a user" is always requested and usually implemented as "sign a normal
token for them", which is indistinguishable from account takeover in the audit log.

## Decision
`POST /api/manager/tenants/{id}/users/{uid}/impersonate` issues an **`app`**-audience token
that is: ≤ 15 minutes, **not refreshable**, carries `act: {sub: <managerId>, realm: manager}`
and `imp: true`. `ImpersonationGuard` denies every route tagged
`#[DeniedUnderImpersonation]` (tenant deletion, billing, member removal, credential change).
Every request under `imp` writes an audit entry naming both identities. The UI shows a
persistent banner with a one-click exit.

## Consequences
- Support can debug; nobody can quietly become a customer.
- Guarded routes must be tagged as they are written - the `Example` module demonstrates it.
