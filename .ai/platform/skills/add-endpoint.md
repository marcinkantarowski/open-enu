# Skill - add an endpoint

An endpoint is six things, and the build fails if any of them is missing. That is the point:
the omission is a build failure rather than a review comment.

---

## 1. The route and the permission

```php
#[Route('/api/invoices/{id}', name: 'invoicing_invoice_show', methods: ['GET'])]
#[IsGranted('invoicing.view')]
public function show(string $id): JsonResponse
```

- The name is `<module>_<noun>_<verb>`, and it is what route coverage, the inventory and the
  MCP tool list all key on.
- The permission must be declared in the module's `Acl/permissions.php`. An undeclared one
  is **refused**, so a typo fails closed.
- A route with no ACL declaration at all fails `make arch`.

## 2. A thin controller

Validate, delegate, serialise. No `EntityManager`, no business logic - enforced by a
PHPStan rule.

```php
$invoice = $this->invoices->get($id) ?? throw new NotFoundHttpException('No such invoice.');

return new JsonResponse($invoice->toArray());
```

Another tenant's record is **not found**, never forbidden: the scope filter removes it from
the query, and a 403 would confirm that the id is real.

## 3. Writes: a command and a handler

```php
return new JsonResponse($this->commands->dispatch(new IssueInvoice(...)), 201);
```

`flush()` outside `Handler/` is a build failure. Push `before`/`after` into
`SnapshotCollector` if the change is one somebody might later have to explain.

For an update to a `VersionedInterface` entity, take `If-Match` and let `OptimisticLock`
turn a stale write into a 409 carrying both versions and the saved record.

## 4. What it returns

- `201` with the record for a create; `202` when the work is queued, not done.
- Errors go through the kernel's exception listener: throw `BadRequestHttpException`,
  `NotFoundHttpException`, `AccessDeniedHttpException` and let it shape the body.
- Reject unknown or unsupported input rather than ignoring it. A setting that "saves" and
  then does not apply is a bug report nobody can reproduce.
- Decide per endpoint what leaves the server. An encrypted column belongs in `show`, not in
  a list.

## 5. A functional test - before you call it done

```php
public function testAnInvoiceIsReadableByItsOwner(): void
{
    $this->givenATenant();
    $this->givenIAmSignedIn();
    // …
}
```

Then the refusals, which is where the value is: another tenant's id, a member without the
grant, a missing field, a replayed token.

```bash
make route-coverage    # the route must appear as hit
```

## 6. The spec and the types

```bash
make openapi && make types
```

`backend/openapi.json` is a committed artefact ([ADR-0012](../adr/0012-openapi-is-the-contract.md))
read by three things: the frontend's type generator, the MCP server and anyone learning the
API without booting it. `make arch` fails if it is stale.

## 7. Strings

Every user-facing string is a key, in `en` **and** `pl`. `make i18n-check` fails otherwise.

---

## Done when

```bash
make check && make ci
```

- [ ] route named `<module>_<noun>_<verb>`, guarded by a declared permission
- [ ] controller is thin; every write is a command
- [ ] a functional test asserting content **and** at least one refusal
- [ ] `make route-coverage` counts it
- [ ] `openapi.json` and `ui-kit/types/api.d.ts` regenerated
- [ ] no hard-coded user-facing string
