# ADR-0016 - The kernel is a package; its name is never the project's name

**Status:** accepted · **Date:** 2026-09-10
**This is the most important invariant in the repository.**

## Context
Two distribution models were available:

- **The reference framework's:** a versioned npm package you never fork, with your code in overlays.
  Framework fixes arrive by `npm update`. This is where its "zero technical debt on updates"
  claim comes from.
- **Boilerplate-as-template:** you copy it, rename it, own it. Simple, and updates to the
  boilerplate never reach projects built from it.

The template model is right for this repository - projects must be able to diverge freely.
But choosing it at Phase 0 *without preparation* closes the other door permanently: once
`OpenEnu\Core\` has been renamed to `MyProject\Core\` in fifty files, no kernel fix can
ever be applied mechanically.

## Decision
Split framework from application at the package boundary, from Phase 0:

| | Package | Namespace | Renamed by `make init`? |
|---|---|---|---|
| Framework | `open-enu/kernel` | `OpenEnu\Kernel\` | **never** |
| Framework UI | `@open-enu/ui-kit` | - | **never** |
| Application | `open-enu/app` → `<project>/app` | `App\` | yes |

The kernel is installed as a composer **path repository** (symlinked, zero ceremony today).
`make init` masks the kernel identifiers before replacing anything, and verifies
afterwards that `backend/composer.json` still requires `open-enu/kernel`.

Every other name the framework defines - container parameters, service tags, the route loader
type, cookies, PHPStan identifiers - is spelled `open_enu_*`, `open_enu.*` or `openEnu.*`.
Those forms contain neither the project slug nor its studly name, so init cannot reach them.

Nothing in `backend/kernel/` may import from `App\`.

## Consequences
- Today: no cost. A symlinked path package behaves exactly like a subdirectory.
- Later: a project may replace the path repository with a versioned tag and `composer update`
  to pull kernel fixes - the never-fork model, available on demand rather than imposed.
- The invariant is fragile in exactly one place (`init.sh`) and is therefore guarded by
  `make selftest`, which is verified to **fail** when the masking is removed.
- Cost: a second `composer.json`, and the discipline that application concerns never leak
  into the kernel.

## Amendment - 2026-09-17: the framework itself was renamed
The framework was called Startenu until its first commit and is now OpenEnu. That is not a
breach of this decision: the invariant protects projects *built from* the framework, and none
existed yet. After the first release the framework's name is as fixed as this ADR says.

The rename exposed a hole the invariant had: framework identifiers **outside**
`backend/kernel/` that contained the slug (`startenu_modules`, `startenu.flag_provider`,
`StartenuKernelBundle`) were rewritten by init, and the renamed application did not boot.
`selftest` checked the package names and nothing that used them. It now counts every
framework identifier before and after init. See
[the spec](../specs/2026-09-17-open-enu-and-shared-edge.md).
