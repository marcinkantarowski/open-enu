# ADR-0025 - Units inside a tenant, and roles a tenant defines

**Status:** accepted · **Date:** 2026-11-11 · **Revises:** [ADR-0014](0014-no-hierarchical-orgs-in-v1.md)

## Context
ADR-0014 left the door open: the scope filter was written for N dimensions so that an
organisation tree inside a tenant would be "a second entry in the map, a claim, a column".
Products built on the platform need it: one customer is one tenant, and inside it there are
branches, departments, sites. Someone at one site must see that site's records and no
other's; the head of a department sees the department and everything under it.

They also need roles the platform cannot know in advance - "front desk", "finance",
"front desk without amounts" - and a customer's administrator expects to define such a role
themselves. Three hard-coded roles (`owner`, `admin`, `member`) cannot say that.

## Decision
1. **A second scope dimension, `unit_id`.** An entity implementing
   `Contract\UnitScopedInterface` is narrowed to a SET of units: `unit_id IN (...)`. The
   filter still fails closed: no unit set in scope means no rows. "Every unit" is an
   explicit value, never the absence of one. A unit-scoped entity is always tenant-scoped too.
2. **Writes are checked as reads are.** A kernel Doctrine listener refuses to persist or
   move a unit-scoped row into a unit outside the current set. A filter alone guards reads;
   a handler that writes into a unit its caller cannot see is the same leak in the other
   direction. With no unit set in scope at all - the system, not a person - it stands
   aside, as writes take the tenant as a parameter rather than from the scope.
3. **Search is narrowed too.** The index is read by DBAL, which the filter cannot see, so
   each entry stores its record's unit and the query filters on it.
4. **The tree is the `Unit` module's**, a platform module: units with a parent, a name and a
   free `kind` ("branch", "site"), stored with a materialised path so "this unit and
   everything under it" is one indexed query. Other modules reach it only through
   `Unit\Contract\UnitTreeInterface`.
5. **A membership has one role and a list of units** (or all of them). The allowed set is
   those units and all their descendants, computed on every request from the tree (two
   indexed queries, no cache to invalidate) - so moving a unit takes effect at once, without
   re-issuing tokens. A row with no unit is the whole tenant's.
6. **Roles belong to the tenant**, in a `Role` module: `role_role(tenant_id, key, name,
   permissions, known, template)`, edited in the tenant's own settings. A membership names
   its role by key. `owner` has every permission, is never stored, cannot be edited or
   deleted, and a tenant always keeps one. `admin` and `member` exist in every tenant with
   the built-in rule until the tenant saves them; the first save stores them. The
   permissions a role may hold are exactly those the modules declare; the owner-only ones
   (`tenant.delete`, `api_key.manage`) cannot be granted to any other role, and nobody grants
   a permission they do not hold themselves.
7. **A role remembers which permissions existed when it was saved** (`known`). A permission
   declared later - a module added by an update - follows the role's template: the built-in
   rule for `admin` and `member`, refused for a tenant's own role. Without this, every
   tenant's admin would lose each new module until an owner ticked it.
8. **One resolver still answers for both the voter and the session**, now from the tenant's
   roles, read once per request and not cached across requests - an edit applies to the
   next request with nothing to invalidate.

## Consequences
- Every place that enters a scope says which units: a request computes them; a worker gets
  them from the stamp it already carries; a console command or a handler acting for the
  system calls `ScopeContext::enterTenant()`, which means the whole tenant, explicitly.
- `make test-security` gains the unit direction: a read outside the set, a write into a unit
  outside it, a worker message, and a membership narrowed while its session is live.
- A role is data, so it is audited like data: every change to a role or a membership goes
  through a command and lands in the trail with before and after.
- People are administered by a `Member` module that owns no table: Identity keeps
  memberships and exposes them through a contract. Nobody hands out more than they hold -
  a role, or a reach - and a tenant always keeps one active owner.
- Per-unit roles (front desk in A, finance in B) are not supported: one membership, one
  role. The cost of the alternative - a permission that depends on the row's unit, in every
  voter and every list - is recorded here so it is not added by accident.

## Rejected
- **Units in the JWT.** A token would keep a moved unit's old reach for its whole lifetime,
  and a large tree would make every request carry it.
- **Roles in a project file.** The customer's own administrator cannot edit a file, and
  "front desk without amounts" is exactly the role a customer invents after go-live.
- **Seeding `admin` and `member` into every tenant.** Every existing tenant would need a
  data migration, and every test tenant a setup run; a role never saved answering with its
  built-in rule needs neither.
- **Roles cached per tenant.** One indexed query per request is cheap, and a cache is one
  more thing a role edit must remember to invalidate - in every process.
- **Nested sets / a closure table.** A materialised path answers the only question asked -
  "descendants of these" - with one `LIKE` on an index, and a move rewrites one subtree.
