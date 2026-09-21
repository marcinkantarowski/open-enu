# Tenancy

One rule, and everything else is its consequence: **a query that does not know which tenant
it is for returns nothing.** Not "everything", not "an error" - nothing. See
[ADR-0004](../adr/0004-shared-db-tenancy.md) for why that direction was chosen.

---

## The three moving parts

| Part | What it does |
|---|---|
| `Contract\TenantScopedInterface` | A marker on an entity: "every row of this belongs to a tenant" |
| `Doctrine\ScopeFilter` | A Doctrine filter that adds `tenant_id = ?` to every query against one |
| `Doctrine\ScopeContext` | Request-scoped state holding which tenant, pushed into the filter |

The filter is enabled always. With a tenant in context it emits `tenant_id = '…'`; with
none it emits `1 = 0`. A forgotten stamp on a worker message, an unauthenticated route, a
CLI command nobody scoped - all of them return an empty list rather than everybody's data.

```php
#[ORM\Entity]
#[ORM\Table(name: 'invoicing_invoice')]
class Invoice implements TenantScopedInterface
{
    // Named explicitly: the filter looks for `tenant_id`, and inferring it would
    // make the filter's behaviour depend on a naming strategy set far from here.
    #[ORM\Column(name: 'tenant_id', type: 'uuid')]
    private Uuid $tenantId;
}
```

That is the whole integration. There is no repository base class to extend and no query to
remember to write - see `backend/src/Module/Example/Entity/Project.php`.

---

## Where the tenant comes from

| Caller | Source |
|---|---|
| A browser request | the `tid` claim on the access token, read by Identity's listener |
| A machine request | the API key's tenant - a key belongs to exactly one workspace |
| A worker message | `Messenger\TenantStamp`, applied when the message is dispatched |
| A console command | nothing. Enter it explicitly, or read nothing |

A worker is the one people get wrong. Messages are dispatched inside a scoped request and
consumed in a long-running process that handles many tenants in a row, so the tenant has to
travel *with the message* - which is what the stamp is for, and why `ScopeContext`
implements `ResetInterface`.

---

## Crossing the boundary on purpose

Some work is genuinely cross-tenant: an operator console, a nightly purge, a GDPR export.
`runUnscoped()` is the only way to do it, and it is deliberately conspicuous.

```php
$stale = $this->scope->runUnscoped(
    'purging tenants that were never verified, across all of them',
    fn (): array => $this->tenants->pendingSince($cutoff),
);
```

The reason is required, not decorative: it is the sentence somebody reads when asking why
this code can see another customer's rows.

### The rule that costs people a day

**Nothing unflushed may cross that boundary, in either direction.**

`runUnscoped()` clears the EntityManager on the way out - it has to, or an entity loaded
outside the filter stays in the identity map and a later scoped `find()` returns it from
memory without ever issuing the SQL the filter would have constrained. The clear is the
isolation guarantee, not an implementation detail.

But it detaches *your* entities too, and flushing a detached entity is not an error in
Doctrine. No exception, no SQL, no write.

```php
// WRONG - the flush writes nothing at all, silently
$user->verify();
$this->tenants->activate($tenantId);   // runUnscoped inside; clears the manager
$this->em->flush();

// RIGHT - flushed before the crossing
$user->verify();
$this->em->flush();
$this->tenants->activate($tenantId);
```

```php
// WRONG - loaded inside, mutated outside: detached by then
$tenant = $this->scope->runUnscoped('…', fn () => $this->tenants->get($id));
$tenant->suspend();
$this->em->flush();

// RIGHT - the whole write lives inside the callback
$this->scope->runUnscoped('…', function () use ($id): void {
    $tenant = $this->tenants->get($id) ?? throw new NotFoundHttpException();
    $tenant->suspend();
    $this->em->flush();
});
```

`runUnscoped()` now refuses to run when the UnitOfWork holds unsaved changes, and names the
entity classes it would have discarded. Both wrong examples above shipped here before that
guard existed - see
[`unflushed-work-does-not-survive-rununscoped`](../lessons/unflushed-work-does-not-survive-rununscoped.md).

---

## Encryption is scoped too

`encrypted_tenant_string` derives its key from the row's tenant, so it may only be used on
an entity that **is** `TenantScopedInterface`. On an unscoped entity the row becomes
unreadable whenever the ambient scope differs from the one it was written under. Use
`encrypted_string` there instead -
[`an-encryption-key-must-belong-to-the-row`](../lessons/an-encryption-key-must-belong-to-the-row.md).

Encrypted columns are never queryable: the ciphertext differs on every write. Add a
`*_hash` sibling written with `Encryptor::hashForLookup()` and query that.

---

## Proving it

`make test-security` is the suite that exists for this one property, and it attacks from
four directions at once: an unscoped request, an unstamped worker message, a direct id
lookup for another tenant's row, and the identity map. `tests/Security/TenantIsolationTest.php`
is worth reading in full before touching anything here;
`tests/Security/ScopeBoundaryTest.php` covers what may and may not cross `runUnscoped()`.

A functional test asserting isolation must re-read from the database rather than assert on
objects it already holds - an assertion against the identity map passes whether or not the
filter did anything.
