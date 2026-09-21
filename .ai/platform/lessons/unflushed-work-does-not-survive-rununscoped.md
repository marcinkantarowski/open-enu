---
tags: [doctrine, tenancy, scope, silent-failure]
date: 2026-09-11
phase: 9
---
# Unflushed work does not survive a scope crossing

## What happened
A functional test for email verification asserted that the link is single-use. Replaying a
consumed token returned `200 {"status":"verified"}` - every time, forever.

The handler looked correct:

```php
$token = $this->tokens->redeem($raw, SecurityToken::PURPOSE_VERIFY_EMAIL);  // sets consumedAt
$user  = $token?->user() ?? throw new BadRequestHttpException(...);

$user->verify();
$this->tenants->activate($token->payload()['tenantId']);   // ← the problem
$this->em->flush();
```

The replay was the small half of it. Nothing this endpoint does was being written at all:
the user was never marked verified, the token was never consumed, and the tenant created at
signup stayed `pending` - so `app:tenant:purge` would eventually have deleted the workspace
of somebody who *had* clicked the link.

`TenantProvisioner::activate()` had the same shape one level down, and so did `suspend()`,
which meant an operator suspending a tenant suspended nothing.

## Why it happened
`ScopeContext::runUnscoped()` calls `$em->clear()` on the way out, and it is right to: an
entity loaded outside the tenant filter must not stay in the identity map, or a later scoped
`find()` returns it from memory without ever issuing the SQL the filter would have
constrained. That clear is the tenant-isolation guarantee, not an implementation detail.

But `clear()` detaches **everything**, including entities the caller mutated before the call
and intended to flush after it. Flushing a detached entity is not an error in Doctrine. No
exception, no SQL, no write, no log line. The code reads exactly like code that works.

Both halves were, again, individually correct. `activate()` legitimately needs to cross the
scope boundary; the handler legitimately mutates two entities. Nothing connected them.

## The rule
**Nothing unflushed may cross a `runUnscoped()` boundary, in either direction.**

- Mutating *before* the call? Flush first.
- Mutating *inside* the callback? Flush inside it, before returning - not after.
- Loading inside and mutating outside is always wrong: the object is detached by then.

`runUnscoped()` now refuses to run when the UnitOfWork holds scheduled inserts, updates or
deletes, and names the entity classes it would have discarded. Turning silent data loss into
a `LogicException` is worth the one-line `flush()` it costs at three call sites.

The generalisation is worth stating on its own: **an API whose failure mode is "nothing
happens" must be made to throw.** A wrong answer gets debugged; no answer gets shipped.

## How it was caught
By route-coverage tracing (PLAN §12.3) forcing a functional test onto `POST /api/auth/verify`,
which had none. The endpoint had worked in manual testing because a browser sees `200
{"status":"verified"}` either way - the response body is built in PHP from objects that
*were* mutated, whether or not the mutation reached Postgres.

Which is the other half of the lesson: the test now empties the identity map and re-reads the
tenant from the database before asserting on it. An assertion made against the object already
in memory would have passed against the broken code too.

## Where else this applies
- Any service that wraps a `runUnscoped()` read and mutates the result afterwards.
- `$em->clear()` anywhere else - a batch import that clears every N rows detaches whatever
  the surrounding code is still holding.
- Messenger handlers that call a cross-tenant service mid-way: the transaction is still open,
  so the earlier `flush()` is not a partial commit, but the identity map is gone.
