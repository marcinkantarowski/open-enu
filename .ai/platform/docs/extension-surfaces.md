# Extension surfaces

"Modify nothing, extend everything" is a slogan until the hooks are listed. These are ours.

Each row says **where to hook in** and **what it costs**. Anything not listed is not an
extension point - if you find yourself editing a file that several features share, stop:
that is the coupling these surfaces exist to avoid.

> **Status:** surfaces marked *(2b)* or later do not exist yet. This file is written by the
> phase that builds each one, so nothing here is aspirational - if it is listed without a
> phase marker, it works today.

---

## Adding behaviour

| To… | Hook | How |
|---|---|---|
| **Add a feature** | a module | `make module NAME=Billing` - backend module, frontend layer, docs, spec and Task Router row in one command |
| **Replace what a service does** | decorate it | `#[AsDecorator(OriginalService::class)]` on your class, take the original as a constructor argument. The original stays untouched and independently testable |
| **React to something happening** | subscribe | `#[AsEventListener]`, or a Messenger handler for a domain event *(2b)*. Many listeners may react to one event; ordering is `priority` |
| **Add fields to every log line** | `LogContextProviderInterface` | Implement it. Auto-tagged by `instanceof`, merged by `ContextProcessor`. A provider that throws is swallowed - it must never take down the request it is annotating |
| **Influence which language a response speaks** | `LocalePreferenceProviderInterface` | Implement it with a `priority()`. The kernel's `Accept-Language` provider sits at 0; anything the user or tenant explicitly chose should outrank it |
| **Add a console command** | put it in `Command/` | Discovered and registered automatically for every enabled module |
| **Add routes** | put a controller in `Controller/` | Attribute routes are loaded per module by `ModuleRouteLoader`, which respects `enabled: false` |
| **Add a permission** | `Acl/permissions.php` | Merged into one global map at compile time. Two modules declaring the same string is a boot failure - a permission with two owners has no meaning |
| **Add a database table** | an entity in `Entity/` + `make diff` | The migration is generated into *your* module's `Migrations/`, not a shared folder |
| **Add translations** | `i18n/messages.{en,pl}.json` | Registered as a translator path per module. Both locales are required (`make i18n-check`) |
| **Add a feature flag** | `FlagProviderInterface` | Implement it in `Service/`, declaring a `FlagDefinition` beside the `#[Flag]` that reads it. `make flags` reconciles declarations into rows an operator can switch. **Without a declaration the flag can never be turned off**, which is the one job a kill switch has |
| **Do work in the background** | a message implementing `JobInterface` | Put it in `Message/`, handle it in `Handler/`, dispatch it *from a handler* so it goes out only once the transaction has committed. Ids only, never entities - it is deserialised in a worker with its own EntityManager |

## Reaching another module

This is the part that decides whether the system stays modular. In order of preference:

| Need | Do this | Do **not** |
|---|---|---|
| Read another module's data | Depend on an interface in its `Contract/` and inject it | Import its `Entity` or `Repository` |
| Do something when it changes | Subscribe to an event in its `Event/` | Call its service from yours |
| Make it do something | Dispatch a command or a message *(2b)* | Call its service from yours |
| Reference its records | Store the UUID; resolve through its `Contract/` | Add a foreign key or an ORM association |

All four "do not" cases are build failures (`make arch`). The error message names the
alternative, so the rule is discoverable at the moment it bites rather than in this file.

**Why the friction is deliberate.** A direct call is always the shortest path, and it is
how a modular monolith becomes a distributed ball of mud: one import, then the entity, then
a join, and the module can no longer be reasoned about or moved. The cost of a contract is
one interface; the cost of skipping it is paid forever.

## Turning things off

| To… | Do this |
|---|---|
| Disable a whole module | `enabled: false` in its `module.yaml` |
| Disable one route or handler | `#[Flag('example.archive')]`, declared in a `FlagProviderInterface`, flipped per tenant from the operator console |

Its routes, services, entity mappings, migrations and permissions all disappear together.
This is why routes are loaded by a module-aware loader and not by a glob: a glob cannot see
`enabled`, so a "disabled" module would still answer HTTP requests.

## Frontend *(Phase 5)*

| To… | Hook |
|---|---|
| Add pages, components, composables | a module layer under `frontend/app/modules/<name>/` - auto-registered |
| Add menu entries | `navigation.ts` in the module layer |
| Override a `ui-kit` component | same path in your app layer; the original stays importable for wrapping |
| Fill a layout region | `<Injection spot="…">` |

---

## What is deliberately *not* extendable

The tenant scope filter, the token audience check, the audit middleware *(2b)* and the
outbox. Those are the guarantees the system makes.

A guarantee you can decorate away is not a guarantee - so there is no hook, and adding one
is an `Ask First` that needs a very good answer.
