---
tags: [testing, cache, mocks]
date: 2026-09-11
phase: 4
---
# Test a contract against the real implementation, not a mock of it

## What happened
`TenantCache` tagged its entries `module:settings`, `tenant:{uuid}`, `settings:{identifier}`.
Every unit test passed. The first time a feature flag was read through it in a request:

> Cache tag "module:settings" contains reserved characters "{}()/\@:".

PSR-6 reserves `{}()/\@:` in keys, and Symfony applies the same rule to **tags**. Two
phases of work sat on top of a cache whose tags could never be written - and the failure
surfaced as a 500 from a flag-guarded endpoint, nowhere near the tag.

## Why it happened
The Phase 2b tests for `TenantCache` mocked `TagAwareCacheInterface`. A mock accepts any
string, so it verified that the class *calls* `tag()` with the value it intended - which was
never in doubt - and could not verify the one thing that mattered: that a real pool would
accept it.

The mock tested the code against itself.

## The rule
**When a collaborator imposes constraints on the values you pass it, test against a real
implementation of that collaborator.** For anything with an in-memory implementation -
caches, filesystems, event dispatchers, serialisers - there is no excuse: `ArrayAdapter`
costs nothing and enforces every rule the production adapter does.

Mock what is slow, remote or nondeterministic. Do not mock something whose *rules* are the
thing under test.

## What changed
`TenantCacheTest` now uses `TagAwareAdapter(new ArrayAdapter())`, and asserts that generated
tags contain no reserved characters. Tag construction moved into named static methods with
a central sanitiser, so a tag built from an arbitrary identifier cannot smuggle one in.

The same test caught a second bug immediately: two tenants must share ONE pool for the
isolation assertion to mean anything. With a pool each, the test passed while proving
nothing.

## Where else this applies
- Storage keys, which the storage backend constrains.
- Message and queue names, which the transport constrains.
- Header and cookie names, which the HTTP layer constrains.
- Same family as [[an-encryption-key-must-belong-to-the-row]]: both passed every unit test
  and failed the first time a real request crossed the boundary.
