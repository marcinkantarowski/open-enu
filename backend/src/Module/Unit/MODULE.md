# Unit

The tree of units inside a tenant - branches, departments, sites - and nothing
else (ADR-0025). Which rows a request sees is the kernel's job (`ScopeFilter`,
`UnitWriteGuard`); which units a person reaches is Identity's (`Membership`).
This module only answers "what is below these units?".

> Budget: 8 KB. Checked by `make agents-budget`.

## Owns

- `Unit` - a node with a parent, a name, a free `kind` and a materialised
  `path` (`/root/child/self/`). Tenant-scoped, not unit-scoped: the whole
  tenant sees the tree.
- Create, rename, move (with its subtree; never under itself) and archive
  (with its subtree). No delete: other modules hold unit ids with no foreign key.

## Public contracts

- `Contract/UnitTreeInterface::expand(list<string>)` - the units and every
  unit below them, in the tenant in scope; unknown ids expand to nothing.
  Not cached: a move applies to the next request with nothing to invalidate.

## Making a module's rows unit-scoped

Implement `OpenEnu\Kernel\Contract\UnitScopedInterface` and add a nullable
`unit_id` uuid column - see `Example/Entity/Project.php`. Reads are narrowed,
writes outside the scope refused, and search results filtered, with nothing
else to write. A row with no unit is seen only by the whole tenant.

## Events

- _(none yet)_

## Permissions

- `unit.view` - read the tree (every role: a form needs a unit picker)
- `unit.manage` - create, rename, move, archive

## Notes for agents

- Moving or archiving changes who sees what. Both go through commands and
  are audited with before/after.
- `make test-security` holds `UnitIsolationTest`: read, write, worker,
  search and a move under a live scope.
