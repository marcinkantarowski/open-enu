# Role

What each role may do in a tenant (ADR-0025). A membership names its role by key; this
module decides what that key grants, and nothing else. It does not know who holds a role:
the module that keeps memberships answers that through `RoleHoldersInterface`.

> Budget: 8 KB. A module that needs more explaining than that is too big -
> split it rather than writing more here. Checked by `make agents-budget`.

## Owns

- `Role` - a tenant's role: key, name, the permissions it grants, and the permissions that
  existed when it was last saved (`known`).
- The three built-in keys. `owner` holds everything and is never stored. `admin` and
  `member` exist in every tenant with the built-in rule until a tenant saves them; the
  first save stores them. A tenant's own roles get a key from their name (`front-desk`).
- `PermissionResolver`: one answer for the voter and the session, from the tenant's roles,
  read once per request - no cache, so an edit applies to the next request.

A permission declared after a role was saved (a module added in an update) is decided by
the role's template: the built-in rule for `admin` and `member`, refused for a tenant's own
role. The owner-only permissions (`OWNER_ONLY`) are never granted to another role, whatever
is stored.

## Public contracts

Other modules may import **only** what is listed here (from `Contract/`).
Everything else is internal and may change without notice.

- `PermissionsInterface` - `allows(tenant, role, permission)`, `forRole(tenant, role)`, and
  the built-in keys.
- `RoleHoldersInterface` - implemented by the module that keeps memberships (Identity), so
  a role still held is never deleted.

## Events

Events this module emits, which other modules may subscribe to. This list is
cross-checked against `Event/` by `make docs:check`.

- _(none yet)_

## Permissions

Declared in `Acl/permissions.php`:

- `role.view` - see the roles and what each grants
- `role.manage` - create, edit and delete roles. Only what the caller holds can be
  granted: managing roles is not a way to every permission.

Every permission a module declares needs a `permission.<name>` label in its `i18n/`: it is
a line on the roles screen. `make i18n-check` fails on one without.

## Notes for agents

- Code checks permissions (`#[IsGranted('example.manage')]`), never a role key.
- Infrastructure this module needs (cache, storage, events, search, progress,
  flags) comes from the kernel - see `.ai/platform/PLAN.md` §6.9. Do not add a local version.
- Cross-module access goes through `Contract/` or an event, never a direct
  import. Enforced by `make arch`.
