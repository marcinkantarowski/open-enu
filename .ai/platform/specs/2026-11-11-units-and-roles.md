# Units inside a tenant, roles a tenant defines, and its members

Decision: [ADR-0025](../adr/0025-units-and-tenant-defined-roles.md). Three pull requests.

## 8a - Units

**Kernel**
- `Contract\UnitScopedInterface` - marker; the entity has a `unit_id` uuid column.
- `ScopeContext::UNITS` dimension: a list of unit ids, or `ScopeContext::ALL_UNITS`.
  `enterTenant(string $tenantId)` enters the tenant with all units; `allowsUnit(string)`.
- `ScopeFilter`: `unit_id IN (...)` for a list, nothing for all, `1 = 0` when absent.
- `UnitWriteGuard` (Doctrine `prePersist`/`preUpdate`): a unit-scoped entity whose
  `unit_id` is outside the set is refused with a 403. Skipped inside `runUnscoped()` and
  when no unit set is in scope (the system writing).
- Search: `search_index` gains `unit_scoped` and `unit_id` (kernel migration); the query
  keeps tenant-wide entries and unit-scoped ones in an allowed unit.
- `TenantStamp` already carries `ScopeContext::all()`; nothing to change but a test.

**Module `Unit`** (`make module NAME=Unit`)
- `Unit` entity, tenant-scoped (not unit-scoped - the tree itself is visible to the tenant),
  versioned: `id, tenant_id, parent_id, name, kind, path, archived_at, version`.
- Commands: `CreateUnit`, `RenameUnit`, `MoveUnit` (refuses a move under its own subtree),
  `ArchiveUnit`. No hard delete: rows in other modules point at a unit.
- `Contract\UnitTreeInterface`: `expand(list<string> $unitIds): list<string>` (the units and
  their descendants, archived included - archiving hides, it does not revoke). Not cached.
- API `/api/units` (GET tree, POST, PATCH `{id}`, POST `{id}/move`, POST `{id}/archive`),
  permissions `unit.view`, `unit.manage`.
- Frontend: settings tab "Structure" - the tree, add a child, rename, move, archive.

**Identity**
- `Membership.unitIds` (jsonb, null = all units). `ScopeListener` enters
  `[TENANT => …, UNITS => owner || null ? ALL : tree->expand(unitIds)]`. API keys: all units.
- Existing `enter([TENANT => …])` callers move to `enterTenant()`.

**Example**: `Project` becomes `UnitScopedInterface` (nullable `unit_id`; `unitId` accepted
on create) - the demonstration of the concern, and what the security suite reads.

**Tests**: kernel - filter SQL for a set, all, absent; guard refuses and allows. Security -
a member narrowed to unit A reads A and A's children, not B; cannot create in B; a worker
message dispatched by them is narrowed the same way; narrowing the membership applies to
the next request.

## 8b - Roles

- `Role` entity in Identity, tenant-scoped: `key, name, permissions (jsonb), system`.
  `Membership.role` stays the role's key. Tenant setup seeds `owner` (system), `admin`,
  `member` with today's grants.
- `PermissionResolver::allows(tenantId, roleKey, permission)`; `owner` always true;
  OWNER_ONLY never for another role. Cache per tenant, dropped by every role command.
- Commands `CreateRole`, `UpdateRole`, `DeleteRole` (refused while a membership uses it).
- API `/api/roles`, permission `identity.role.manage`. A permission catalogue endpoint
  groups the declared permissions by module, with translated labels
  (`permission.<name>` keys in each module's catalogue - `i18n-check` requires them).
- Frontend: settings tab "Roles" - list, create, edit with checkboxes by module.

## 8c - Members

- API `/api/members`: list (with role and units), invite by e-mail (a `SecurityToken` and
  the existing `invitation.html.twig`), change role and units, suspend, reactivate. The last
  active owner cannot be demoted, suspended or removed.
- Frontend: settings tab "Members" - table, invite dialog, edit drawer with role select and
  a unit picker over the tree.
- Accepting an invitation: a page that sets a password for a new account, or adds the
  membership to an existing one after login.

## Rejected
See the ADR.

## Changelog
- 2026-11-11 - written.
- 2026-11-11 - 8a built: the tree is not cached; system writes are not narrowed; search
  narrowed; `Unit` versioned (a PATCH endpoint needs optimistic locking - arch test).
