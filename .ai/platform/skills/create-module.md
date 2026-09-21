# Skill - create a module

A feature exists in two places that mirror each other: `backend/src/Module/<Name>/` and
`frontend/app/modules/<kebab-name>/`. Both are discovered from the directory, so there is
no central file to register anything in - and no central file to forget.

---

## 1. Check it does not already exist

```bash
grep -i '<thing>' .ai/inventory.json
make modules
```

A feature that belongs *inside* an existing module goes there. A new module is for a new
noun with its own lifecycle, not for a new verb on an existing one.

## 2. Scaffold it

```bash
make module NAME=Invoicing
```

Never by hand. One written by hand differs in ways nobody notices until the third one, and
this generator syntax-checks what it writes. It creates:

```
backend/src/Module/Invoicing/{Contract,Controller/Api,Dto,Entity,Repository,Service,
                              Event,Security/Voter,Acl,Migrations,Fixtures,Tests}
                              module.yaml  MODULE.md  i18n/messages.{en,pl}.json
frontend/app/modules/invoicing/{nuxt.config.ts,navigation.ts,
                                i18n/locales/{en,pl}.{json,ts},pages/,components/}
.ai/specs/<today>-invoicing.md   # .ai/platform/specs/ in the platform's own repository
AGENTS.md  → a Task Router row
```

## 3. Write the spec first

The spec stub is already there, with its row in the index. Fill it in before writing code if the
work has three or more steps or makes an architectural decision. It is cheap and it is what
the next person reads.

## 4. The entity

Start from `backend/src/Module/Example/Entity/Project.php`. Every cross-cutting concern is
one line there:

```php
class Invoice implements TenantScopedInterface, VersionedInterface
{
    #[ORM\Column(name: 'tenant_id', type: 'uuid')]
    private Uuid $tenantId;
}
```

- `TenantScopedInterface` - every query gains `tenant_id = ?`, and with no tenant returns
  nothing. Almost always right. See [`tenancy.md`](../docs/tenancy.md).
- `VersionedInterface` - a concurrent edit becomes a 409 carrying both versions instead of
  a silent overwrite. Right for anything a person edits.
- **No cross-module foreign keys.** Reference another module's record by a plain indexed
  uuid, and reach its behaviour through its `Contract\`. Enforced
  ([ADR-0002](../adr/0002-module-boundaries.md)).

Then:

```bash
make diff MODULE=Invoicing    # a migration for THIS module's tables only
make migrate
```

## 5. Writes go through the bus

Command + handler, always; `flush()` outside `Handler/` is a build failure. See
[`commands-and-audit.md`](../docs/commands-and-audit.md).

## 6. Declare what the module offers

| File | Declares |
|---|---|
| `Acl/permissions.php` | `['invoicing.view', 'invoicing.manage']` - an undeclared one is refused |
| `search.php` | which fields are indexed, and the permission to see them |
| `Service/*Flags.php` | feature flags, or they can never be switched on |
| `Setup/*TenantSetup.php` | what a brand-new tenant needs |
| a `GdprSubjectInterface` | **required** if the module holds a `user_id`. Checked |

Never index an encrypted column: the index stores plaintext and returns excerpts of it.

## 7. The frontend layer

The directory is the registration. Contribute pages, a `navigation.ts` entry, a
settings tab and translations; **never** set `srcDir` or any other path in a layer's
`nuxt.config.ts` - a layer's config merges into the *app's*, so it moves the app's own
pages out from under it with no error at all
([lesson](../lessons/a-layers-config-is-the-apps-config.md)).

Every request goes through `useApi()`. Never `fetch` or `$fetch` from a component.

## 8. Tests

At least one functional test per endpoint - this is measured, not encouraged
(`make route-coverage`). Extend `App\Tests\Support\ApiTestCase`; assert content, never only
a status code; assert the refusals. See [`testing.md`](../docs/testing.md).

## 9. Finish

```bash
make check        # after every edit
make openapi && make types
make inventory
make ci
```

`MODULE.md` must list the module's `Contract/` and `Event/` files exactly -
`make docs-check` compares them to the directory.

---

## Done when

- [ ] `make ci` is green
- [ ] every endpoint has a functional test, and `make route-coverage` says so
- [ ] `MODULE.md` describes what is there, and a Task Router row points at it
- [ ] `.ai/inventory.json` is regenerated
- [ ] no string reaches a user without a key in both locales
