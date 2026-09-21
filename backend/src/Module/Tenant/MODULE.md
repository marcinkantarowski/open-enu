# Tenant

Owns the tenant record - the scope every other module's data hangs off - and its lifecycle.

## Owns

- `Tenant`: slug, name, status, plan, default locale. **Not** tenant-scoped itself; it is
  the thing being scoped to.
- Provisioning: creating a tenant runs every module's `TenantSetupInterface` in dependency
  order, inside the new tenant's scope.
- Activation: a tenant is `pending` until its first user verifies their address, and is
  purged if they never do.

## Public contracts

- `Contract\TenantReaderInterface` - does this tenant exist, is it active, what is its
  default locale, and (for the operator console) the paginated list. Cached, because the
  first three are consulted on every authenticated request.
- `Contract\TenantProvisionerInterface` - create, activate and suspend. Used by Identity at
  signup and verification, and by Manager to suspend; what those mean, and what has to be
  invalidated for them to take effect, stays this module's business.

Both return plain data, never the entity. Handing out the entity would let another module
mutate a tenant without going through this module's commands.

## Events

- `Event\TenantCreated` - a tenant now exists.
- `Event\TenantDeleted` - **emitted before the row is removed**, so listeners can still
  resolve what they are cleaning up. This is how cross-module integrity works here: there
  are no foreign keys to tenant (ADR-0002), so every module holding its data lets go on
  this event. The Tenant module never learns what anyone else stores.

## Permissions

- `tenant.view`
- `tenant.manage`

`tenant.delete` is owner-only and is enforced by `PermissionVoter`, not declared here.

## Notes for agents

- Do not add a foreign key to `tenant`. Store the UUID and react to `TenantDeleted`.
- `TenantOwnedVoter` guards writes. It fails closed: with no tenant in scope, nothing is
  owned - otherwise every unauthenticated path becomes a write primitive.
