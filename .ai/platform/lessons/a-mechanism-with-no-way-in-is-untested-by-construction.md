---
tags: [feature-flags, testing, completeness, phase-4]
date: 2026-09-11
phase: 6
---
# A mechanism with no way to create its inputs is untested by construction

## What happened
Phase 4 shipped feature flags, and shipped them well: a `#[Flag]` attribute that 404s a
route, a resolution chain of tenant override → global default → the attribute's fallback,
tag-based cache invalidation so a flip takes effect on the very next request, a manager UI to
edit them, and a security test proving a flag turned off for tenant A leaves tenant B alone.

**Nothing in the repository could create a flag.** There was no console command, no
declaration file, no endpoint, and no fixture - `Setting` rows came into existence only
inside the one security test that wrote one by hand. So:

- `GET /api/settings` returned `{"items": []}` for every tenant, forever.
- The operator console's flags page listed nothing.
- `demo.archive` guarded a route that could never be enabled.

Every test passed. The security test passed *because* it wrote its own row and then asserted
on the behaviour around it - which is exactly the shape that hides the gap.

## Why it happened
The feature was specified as a resolution chain, and the chain was built and tested
end to end. Nobody asked where the first link comes from, because every test supplied it.

The general form: **a test that constructs its own inputs cannot notice that nothing else
can.** Fixtures are supposed to do that - it is their job - so the absence is invisible
precisely where you would expect to catch it.

## The rule
For any mechanism, ask three questions and make sure something in the repository answers
each of them without a test's help:

1. **How does an input get created** the first time, in a real environment?
2. **Who can see it** once it exists?
3. **Who can change it**, and does the change take effect?

Phase 4 had solid answers to (2) and (3) and no answer at all to (1).

The fix here was a kernel extension point - `FlagProviderInterface` + `FlagDefinition` -
with modules declaring flags beside the code they guard, reconciled by `app:flags:sync`,
which `make builddev` runs. Declaration now sits next to the `#[Flag]` that consumes it, so
the two are hard to separate.

## How it was caught
By needing a flag to be *on*. Phase 6's acceptance criteria are "archive shows progress"
**and** "flag off → route 404", and the first of those is the first time anything in the
project had to switch a flag on rather than merely observe it off. A single criterion
requiring the other state found a gap four phases of green tests had not.

## Where else this applies
Everywhere a value is read far more often than it is written:

- **Permissions** - declared in `Acl/permissions.php` and merged at compile time, so this
  one is fine. It is the model the flag fix copied.
- **Settings that are not flags** - same mechanism, same fix.
- **Notification types and webhook event names** (Phase 7): both are registries that will be
  read constantly and written once. Build the declaration surface with the reader.
- **Any seed, default or catalogue.** If the only code that creates one lives in a test, it
  does not exist.
