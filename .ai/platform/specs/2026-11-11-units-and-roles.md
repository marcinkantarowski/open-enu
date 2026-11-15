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

- A `Role` module of its own (Identity is at its context budget): `role_role`, tenant-scoped:
  `key, name, permissions, known, template`, versioned. `Membership.role` stays the role's
  key (widened to 40).
- Built-ins: `owner` is never stored. `admin` and `member` exist in every tenant before
  they are saved - a role never saved answers with the built-in rule, so no seed and no
  data migration - and the first save stores them (version 0 until then, so even the first
  save is checked for a conflict).
- `known`: the permissions that existed when the role was saved. One declared later
  follows the role's template (built-in rule for admin/member, refused for a tenant's own).
- `Role\Contract\PermissionsInterface::allows(tenantId, roleKey, permission)`, used by the
  voter and the session. Grants read once per request (`ResetInterface`), not cached.
- `Role\Contract\RoleHoldersInterface`, implemented by Identity: a held role is not deleted.
- Commands `CreateRole`, `UpdateRole`, `DeleteRole`; audit `role.created|updated|deleted`.
- API `/api/roles` (`role.view`), `/api/roles/permissions`, POST, PATCH and DELETE
  `/api/roles/{key}` (`role.manage`). Nobody grants what they do not hold themselves.
- Every declared permission has a `permission.<name>` label; `i18n-check` requires it,
  `make module` scaffolds it, `selftest` proves the check fails.
- Frontend: settings tab "Roles" - list, create, edit with checkboxes by module; boxes the
  editor does not hold are disabled.

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
- 2026-11-15 - 8b built: roles are a `Role` module (Identity hit its context budget);
  `admin` and `member` exist unsaved instead of being seeded; `known` + template for
  permissions declared after a save; grants read per request, not cached; no granting
  what you do not hold.
