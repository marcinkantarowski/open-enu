---
tags: [tenancy, kernel, contracts, listeners]
date: 2026-09-11
phase: 7
---
# Taking context from ambient state breaks the callers that legitimately have none

## What happened
Search indexing was made automatic: a Doctrine listener reindexes any entity a module has
declared in its `search.php`, so no handler has to remember to. The indexer took the tenant
from `ScopeContext`, like everything else that is tenant-scoped, and failed closed when
there was none:

```php
throw new LogicException('Search requires a tenant in scope. …');
```

Eight tests in the security suite went red immediately - including
`testRunUnscopedRequiresAReason`, which has nothing to do with search. Every one of them was
a fixture written inside `runUnscoped()`:

```
LogicException: Search requires a tenant in scope.
  PostgresTsvectorIndexer.php:125
  SearchIndexListener.php:38
  TenantIsolationTest.php:68
```

The listener had turned a deliberate, reasoned `runUnscoped()` - the escape hatch the whole
tenancy design is built around - into a hard failure.

## Why it happened
Fail-closed-on-ambient-scope is the right rule for a **read**: a query with no tenant must
return nothing rather than everything, because there is no other source of truth about who
is asking.

It is the wrong rule for a **write of a row that already knows its own tenant**. The entity
implements `TenantScopedInterface`; `$entity->tenantId()` is right there and is authoritative.
Reaching past it to the ambient scope replaced a fact with a guess, and then punished the
callers who legitimately had no ambient scope: fixtures, operator actions, GDPR erasure,
console commands, workers.

## The rule
**If the caller already holds the context, take it as a parameter.** Ambient state is for
when there is genuinely no better source - and then failing closed is right.

The fix made the asymmetry explicit in the interface itself, which is where it is now
impossible to miss:

```php
public function index(string $entityType, string $entityId, array $fields, string $tenantId): void;
public function remove(string $entityType, string $entityId, string $tenantId): void;
public function search(string $query, ?array $entityTypes = null, int $limit = 20): array;   // ambient
```

Writes take the tenant. Reads do not, and stay fail-closed.

## How it was caught
By the security suite, on an unrelated test. That is the value of having one: a change in
the kernel's search code broke tenancy fixtures, and the suite that owns tenancy is what
said so.

## Where else this applies
- Anything added to a Doctrine listener, which runs on **every** write in the process -
  including the ones made by tests, migrations and console commands, which have no request.
- Audit, cache tagging and storage prefixes all read the ambient tenant. They are on the
  request path today; the moment one of them is invoked from a listener, this applies.
- The general shape: a service that is fine when called from a controller and explodes when
  called from a worker is usually reading ambient state it could have been handed.
