# Landing rebuilt after loss

## Why

`landing/` was lost from the working tree on 2026-09-17, during an `npm install` run in the
node container. Only three phase-0 files had ever been staged, so git could not restore it.
It was rebuilt using an earlier production project's landing site as the structural reference: sticky
header, hero, feature grid, steps, FAQ, CTA band, legal pages.

Before a package-manager run on a tree with untracked work, copy what git cannot restore.

## Shape

- One `@nuxt/content` collection per locale (`pages_en`, `pages_pl`), with the locale
  directory stripped from the path. The visitor's locale picks the file; the URL names
  the page.
- **No fallback to English.** A missing translation is a failure, not a silent downgrade.
  `make i18n-check` now also compares the page sets in `content/en` and `content/pl`.
- Copy lives in frontmatter plus markdown bodies. Templates own layout, never words.
  Navigation and footer text go through the i18n catalogue.
- Content links are `/route` or `app:/path`. The app host derives from `DOMAIN`, so it is
  resolved at render time (`useSiteLink`).
- `make docs-check` resolves `/route` links inside `landing/content/<loc>/` to
  `<loc>/<route>.md`, so a link to a page that is missing in one language fails.

## The dependency that kept landing down

`@nuxt/content` needs `better-sqlite3` ^12.5 || ^13. `landing` declared ^11, so the
compatible copy was only an optional peer at the root, and npm does not install optional
peers. A fresh `node_modules` volume (created by the project rename) therefore lacked it.

Declaring `^13.0.3` was not enough. The lockfile entry still carried `hasInstallScript: true`
from its optional-peer life, so npm ran `node-gyp rebuild`. That needs Python, which the
alpine image does not have. The published package is `gypfile: false` and ships musl
prebuilds. Dropping the stale lock entry and re-resolving fixed it.

## Changelog

- 2026-09-21 - **The landing site became a template.** It described this platform as if it
  were the product, which is wrong for every project made from it. The home page is now a
  "coming soon" hero with two links (register, sign in). The pricing page and its content
  are gone; `plans`, like every other section, is switched on by frontmatter on any page,
  so `app/pages/pricing.vue` was folded into `[...slug].vue`. Shapes and examples moved to
  `.ai/platform/docs/landing.md`. Footer tagline and contact page no longer talk about
  this platform.
- 2026-09-21 - **One URL per language.** "The visitor's locale picks the file; the URL names
  the page" made the Polish site impossible to index. `landing` now uses
  `prefix_except_default` (`/contact`, `/pl/contact`). The content layout is unchanged. See
  [landing SEO](2026-09-21-landing-seo.md).
