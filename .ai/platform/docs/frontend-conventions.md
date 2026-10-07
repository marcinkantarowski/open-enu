# Frontend conventions

Three apps, one shared layer, and one Nuxt layer per feature.

```
ui-kit/          @open-enu/ui-kit - the layer all three extend. Never renamed by `make init`.
frontend/        app.${DOMAIN}     - the tenant application. SPA.
manager/         manager.${DOMAIN} - the operator console. SPA, separate bundle.
landing/         ${DOMAIN}         - marketing. Server-rendered, @nuxt/content.
e2e/             Playwright, against the running stack. `make e2e`, ci only.
```

## The rules that are not negotiable

**Nothing calls `$fetch` or `fetch` directly.** Every request goes through `useApi()`,
because four behaviours have to be identical everywhere: the in-memory access token, one
shared silent refresh on 401, `credentials: 'include'` so the refresh cookie travels, and
409 → a typed `ConflictError`. A component that fetches on its own gets none of them, and
the failure is invisible until a token expires.

**No user-facing string in a component.** Every one is a translation key with an entry in
both `en.json` and `pl.json` (ADR-0020); `make i18n-check` fails on a key present in one.

**API types are generated, never written.** `ui-kit/types/api.d.ts` comes from
`backend/openapi.json` via `make types`, and the committed copy is checked by `make arch`.
A backend DTO that loses a field becomes a type error, not `undefined` in a browser.

**A layer never sets a path.** `srcDir`, `dir.*` and friends in a module's `nuxt.config.ts`
become the *app's* - see [[a-layers-config-is-the-apps-config]], which cost an afternoon.

## Adding a feature

`make module NAME=Billing` writes both halves. The frontend one:

```
frontend/app/modules/billing/
├── nuxt.config.ts       the layer manifest - i18n only, never paths
├── navigation.ts        defineNavigation([...]) - menu entries, permission-filtered
├── settings-tabs.ts     defineSettingsTab([...]) - optional, for a Settings screen
├── injections.ts        defineInjection(...) - optional, to extend someone else's page
├── pages/billing/       auto-registered; the directory IS the URL
├── components/ composables/ stores/ types/
└── i18n/locales/{en,pl}.{json,ts}
```

Nothing central is edited. `frontend/nuxt.config.ts` discovers the directory, `useNavigation`
globs the `navigation.ts` files, and deleting the folder removes the feature with no dangling
imports (ADR-0010).

The `.ts` beside each `.json` catalogue is a two-line re-export and is not optional: pointing
the i18n loader at a `.json` puts Vite's json plugin around the module's own transform and
breaks server rendering. The `.json` stays because the parity check reads it as data.

### The dashboard, and the reference module

The dashboard (`frontend/app/pages/index.vue`) has no content of its own: a greeting and
`<InjectionPoint name="dashboard.widgets">` in a two-column grid. What belongs on a product's
first screen belongs to its modules, so a module puts a card there from its `injections.ts`:

```ts
defineInjection('dashboard.widgets', BalanceWidget, { id: 'wallet.balance', order: 10 })
```

`order` decides the position; a card that wants the full width adds `lg:col-span-2` to its own
root. A widget fetches its own data and must render nothing for someone without the
permission to see it.

`Example` is the platform's reference module and is not a product screen. Its menu entry is
marked `demo: true` and is offered only while `DEMO_CONTENT` is on (`.env`; on by default, a
product switches it off). The module, its routes and `/example` stay either way, because the
platform's browser and security tests stand on them - never delete it to tidy a menu.

### Screens for an operator

Most features have nothing for an operator to do, so `make module` writes no operator half.
When one does - a balance to credit, a request to approve - `make manager-module NAME=Billing`
gives the existing module a layer in the console:

```
manager/app/modules/billing/
├── nuxt.config.ts       the layer manifest - i18n only, never paths
├── navigation.ts        defineNavigation([...]) - entries after the console's own; may be empty
├── injections.ts        defineInjection('manager.tenant', Card) - optional
├── pages/billing/       optional; the directory IS the URL
├── components/
└── i18n/locales/{en,pl}.{json,ts}
```

Same discovery, same rules, two differences. There is no `permission` to filter by: an operator
has a role, and the realm is the gate, so every endpoint a layer calls is `/api/manager/...`
behind `ROLE_PLATFORM_MANAGER`. And the usual way in is not a page but a card on the tenant
page: `<InjectionPoint name="manager.tenant">` renders whatever modules registered and hands
each one `tenantId`. A tenant's balance, limits and pending requests then sit where the
operator already is, and the tenant page never learns which modules exist.

**The screen to copy is `frontend/app/modules/example/pages/example/index.vue`.** It is the only
place where optimistic locking, the conflict bar, a realtime refresh, an upload and a
flag-guarded action are all shown working against the real API at once.

## What the shared layer gives you

| Need | Reach for |
|---|---|
| Any HTTP call | `useApi()` - `get/post/put/patch/del`, `ifMatch`, typed errors |
| Login, logout, restore, switch tenant | `useAuth()` (`useOperator()` in `manager/`) |
| Who is signed in, what they may do | `useAuthStore()` - `can()`, `role`, `tenantName` |
| A 409 | `useConflict()` + `<ConflictBar>` - reload / overwrite / dismiss |
| Live updates | `useAppEvent('example.project.created', fn)` - one stream per tab |
| A long job | `useProgress(id)` or drop in `<JobProgress :job-id>` |
| Uploading a file | `useUpload()` or `<UploadField :owner>` |
| Telling the user something | `useToast()` - errors stay until dismissed |
| A feature flag | `useFlags()` - advisory; the server still 404s a disabled route |

## Permissions

`auth.can('example.manage')` decides what to **render**. It never decides what is allowed -
the server checks every request against the same rules, from
`Identity\Service\PermissionResolver`, which is also what computes the list the session
carries. Hide a control the session cannot use; never rely on hiding it.

## Why two SPAs and one server-rendered site

The access token lives in memory and the refresh cookie is `httpOnly` and host-scoped to the
API, so a server render of `frontend` or `manager` has no session to render *with* - it would
emit a signed-out shell that flips on hydration. Neither is a page anyone needs indexed.
`landing` is the opposite on every count, so it renders on the server.

## Before proposing a change

```bash
make typecheck   # all three apps, against the generated API types
make i18n-check  # every locale file has the same keys
make e2e         # the browser suite - needs the stack up
```
