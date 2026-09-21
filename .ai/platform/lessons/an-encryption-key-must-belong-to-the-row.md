---
tags: [encryption, tenancy, doctrine]
date: 2026-09-11
phase: 3
---
# An encryption key must be a property of the row, not of the ambient context

## What happened
`EncryptedStringType` derived its per-tenant key from `ScopeContext` - whatever tenant
happened to be current when the value was written or read. That works perfectly for
tenant-scoped entities and is silently broken for everything else.

`User` is deliberately **not** tenant-scoped: a person belongs to several tenants
(ADR-0005). So a user's encrypted email was written during signup under one scope and read
during login under another - and failed with:

> Decryption failed. Either the master key changed, or this row belongs to a different
> tenant than the current scope.

An error that blames the master key, for a bug that has nothing to do with it.

## Why it happened
A Doctrine DBAL type sees a **value**, never the entity it belongs to. So it cannot ask
"which tenant owns this row?" and has to substitute something it can reach - the ambient
scope. That substitution is only correct when the query filter guarantees the row is read
under its own tenant, which is exactly and only true for `TenantScopedInterface` entities.

The failure is worse than a plain bug: the data is intact and unreadable, and the error
points at the one thing that is fine.

## The rule
**When a stored value can only be interpreted with a key, the key must be derivable from
the row - not from whatever context is current.** If the mechanism cannot see the row, it
must not guess.

So the choice is made in the mapping, where the entity's ownership IS known:

| Type | Key | Use on |
|---|---|---|
| `encrypted_string` | global | anything - always readable |
| `encrypted_tenant_string` | the ambient tenant | **only** `TenantScopedInterface` entities |

The tenant-keyed variant keeps what ADR-0018 promised - cryptographic erasure by rotating
one tenant's salt - for the data that claim actually applies to.

## How it was caught
A functional test, not review. Every unit test passed: they encrypt and decrypt under the
same scope, which is the case that works. It surfaced only when a *request* wrote under one
scope and read under another.

## Where else this applies
- Any signed or encrypted artefact whose key varies: storage URLs, export bundles, webhook
  signatures. Ask what the key is derived from, and whether that thing travels with the data.
- The related trap in tests: `KernelBrowser` reboots the kernel between requests, so a
  fixture written afterwards uses a *different* container's ScopeContext. `disableReboot()`.
