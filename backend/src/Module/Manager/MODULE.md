# Manager

Owns the platform-operator realm: a separate identity, a separate firewall, a separate app.

## Owns

- `PlatformManager` - an operator. A separate **table**, not a role on `User`. That
  separation is what makes realm isolation possible at all; the `aud` claim is what makes it
  actually hold (ADR-0007).
- Operator login, with the strictest rate limit in the system.
- Tenant administration: list, inspect, suspend.
- **Impersonation** - the one legitimate crossing between realms (ADR-0008).
- The cross-tenant audit viewer and worker-queue status.

## Public contracts

_(none - nothing depends on this module. It is the top of the dependency graph, which is
where the authority to read across tenants belongs.)_

## Events

_(none yet.)_

## Permissions

None. Operator authority is a **role** on the operator record, not a dotted permission
resolved from a membership. Keeping the two vocabularies disjoint means no tenant role can
ever accidentally satisfy an operator check, whatever someone types in a controller.

## Notes for agents

- The `manager_api` firewall **must** be declared before `api` in `security.yaml`, or
  pattern matching falls through and operator requests resolve against the tenant provider.
- This module depends only on other modules' `Contract\` surfaces - it holds the most
  authority in the system and is therefore the one place where reaching into internals would
  be least visible and most damaging.
- Everything that reads across tenants does so through an explicit `runUnscoped()` with a
  reason. That is the difference between a designed exception and a hole.
- There is no operator signup endpoint and there should never be one. The first operator
  comes from `app:manager:create`, which needs shell access.
