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
6. **Roles belong to the tenant.** `identity_role(tenant_id, key, name, permissions)`, edited
   in the tenant's own settings. `owner` is a system role: it has every permission, cannot
   be edited or deleted, and a tenant always keeps one. `admin` and `member` are seeded as
   ordinary roles a tenant may change. The permissions a role may hold are exactly those the
   modules declare; the owner-only ones (`tenant.delete`, `api_key.manage`) cannot be granted
   to any other role.
7. **One resolver still answers for both the voter and the session**, now from the tenant's
   roles, cached per tenant and dropped on every role change, so an edit applies to the next
   request.

## Consequences
- Every place that enters a scope says which units: a request computes them; a worker gets
  them from the stamp it already carries; a console command or a handler acting for the
  system calls `ScopeContext::enterTenant()`, which means the whole tenant, explicitly.
- `make test-security` gains the unit direction: a read outside the set, a write into a unit
  outside it, a worker message, and a membership narrowed while its session is live.
- A role is data, so it is audited like data: every change to a role or a membership goes
  through a command and lands in the trail with before and after.
- Per-unit roles (front desk in A, finance in B) are not supported: one membership, one
  role. The cost of the alternative - a permission that depends on the row's unit, in every
  voter and every list - is recorded here so it is not added by accident.

## Rejected
- **Units in the JWT.** A token would keep a moved unit's old reach for its whole lifetime,
  and a large tree would make every request carry it.
- **Roles in a project file.** The customer's own administrator cannot edit a file, and
  "front desk without amounts" is exactly the role a customer invents after go-live.
- **Nested sets / a closure table.** A materialised path answers the only question asked -
  "descendants of these" - with one `LIKE` on an index, and a move rewrites one subtree.
