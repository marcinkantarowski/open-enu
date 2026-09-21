---
tags: [nuxt, layers, configuration, silent-failure]
date: 2026-09-11
phase: 5
---
# A layer sets the app's config, not its own

## What happened
Every module in `frontend/app/modules/*` is a Nuxt layer. Module layers keep their pages at
`<module>/pages/`, not `<module>/app/pages/`, so - reasoning that Nuxt 4 defaults `srcDir`
to `app/` - each module's `nuxt.config.ts` got:

```ts
export default defineNuxtConfig({ srcDir: '.' })
```

The tenant app then served a page with no routes at all. Not a 404 from the router: the
router had nothing in it. `.nuxt/types/middleware.d.ts` read `export type MiddlewareKey =
never`, no `routes.mjs` was generated, and the browser console said
`No match found for location with path "/login"` for a page that plainly existed on disk.

## Why it happened
`extends` merges a layer's config **into the app's**. `srcDir: '.'` in a layer did not scope
`.` to that layer - it set the application's `srcDir` to the application's own root. The
app's `pages/`, `layouts/`, `middleware/` and `plugins/` all live under `app/`, so the
scanner looked in a directory that contains none of them and found nothing to complain
about.

Eight module layers each set it. The last one to merge won. Nothing warned.

## The rule
**Treat a layer's config as a patch applied to the app, not as settings for the layer.**
Anything path-shaped - `srcDir`, `dir.*`, `rootDir` - belongs to the app and must not be set
from a layer.

Two consequences, both now in the repo:

- Each app **pins** `srcDir: 'app'` in its own `nuxt.config.ts`, so no layer can move it.
- Module layers set no paths at all; the generated template carries a comment saying why,
  because the natural instinct is to add one back.

## How it was caught
By a browser. `curl` returned 200 and a plausible HTML shell - the Nitro server was fine -
and the type-check passed, because a missing route is not a type error. The end-to-end suite
failed on `getByTestId('login-email')` timing out, which reads like a slow page rather than
like an empty router; the console warning was the thing that named it.

## Where else this applies
- `app.head`, `i18n`, `runtimeConfig` and `css` all merge the same way - merging is the
  point of layers - but for those, merging is what you want. Paths are the exception.
- The same trap exists for any tool with layered config: a value that is relative to
  "here" means something different once "here" is the consumer's directory.
