# Settings

Owns runtime configuration and kill switches: global defaults with per-tenant overrides.

## Owns

- `Setting` - a definition and its global default. **Not** tenant-scoped: a definition
  belongs to the platform, and tenants attach overrides to it.
- `SettingOverride` - one tenant's answer. Absence means "inherit", so removing a row
  restores the default rather than pinning yesterday's value.
- `PersistentFlags` - decorates the kernel's configuration-only implementation, so every
  `#[Flag]` and `FlagsInterface` call written in Phase 2b now reads live values with no call
  site changing.

## Public contracts

_(none - the kernel's `FlagsInterface` is the contract; this module implements it.)_

## Events

_(none yet.)_

## Permissions

- `settings.view`
- `settings.manage` - changes a tenant's OWN settings, and only those marked
  `tenantEditable`. Platform kill switches are not: a tenant re-enabling a feature an
  operator turned off during an incident would defeat the point of turning it off.

## Notes for agents

- Values are **boxed** under a `value` key. A JSON column holding `false` and one holding
  SQL NULL are indistinguishable through several driver layers, and a deliberately-disabled
  flag reading as "unset" is the exact failure a kill switch cannot have.
- Writes invalidate both the tenant tag and the module tag, so a flip takes effect on the
  next request rather than when a cache entry happens to expire.
- A disabled flag makes its route **404, not 403**: "not built yet" and "turned off after an
  incident" should be indistinguishable from outside.
