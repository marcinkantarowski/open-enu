# ADR-0024 - UI components on Reka UI, owned in `ui-kit`, styled by role tokens; a project's look is its `theme/` layer

**Status:** accepted · **Date:** 2026-11-11

## Context
`ui-kit/app/components/ui/` held twelve hand-written primitives - a button, a field, a
native `<select>`, a `<dialog>` modal, a plain table. They were enough to prove the platform
and not enough to build a product on: no menu, combobox, tabs, tooltip, drawer, date input,
sortable or paginated table, and every interactive one would have had to re-learn keyboard
handling, focus return and ARIA by hand. That is the part hand-rolled components get wrong.

And a project had no way to change how the platform looks. The tokens were
`--color-brand-*` in `ui-kit`'s `main.css` - a platform file - so a project's palette, its
fonts and its logo were all edits to files that `make platform-update` brings up to date.

## Decision
1. **Behaviour from Reka UI, code in the repository.** Every interactive component is a
   Reka UI primitive wrapped in a `Ui*` component under `ui-kit/app/components/ui/`, written
   the way shadcn-vue writes them - copied in and owned, not installed and updated by a CLI.
   Focus, keyboard and ARIA come from Reka; the markup and the classes are ours.
2. **Tables on TanStack Table, headless.** `UiTable` keeps its column/slot API and gains
   sorting and paging through `@tanstack/vue-table`. Server mode is the default: the page
   asks the API for a sorted page, the table does not sort what it was handed.
3. **Icons from `@lucide/vue`, one import per icon.** Never the whole set.
4. **`cn()` = `clsx` + `tailwind-merge`**, so a class passed by the caller replaces the
   component's own instead of fighting it. Variants stay typed `Record` maps, as before -
   no `class-variance-authority`.
5. **Components use role tokens only.** `--color-accent`, `--color-canvas`, `--color-surface`,
   `--color-fg`, `--color-border-input`, `--color-ok` and the rest - never a palette value.
   The platform ships neutral values for them.
6. **A project's look is one optional Nuxt layer: `theme/`, at the repository root.** The
   platform never ships it, so an update never touches it. When it exists, each app extends
   it *before* `ui-kit`, so a component in it - `BrandMark.vue`, typically - replaces the
   platform's, and its `public/` holds the fonts, the favicons and the logo. Its CSS sets the
   role tokens unlayered on `:root`, which outranks Tailwind's `@layer theme` without
   depending on the order the layers' stylesheets load in.

## Consequences
- A product looks like itself without a line changed in a platform file: `theme/` holds the
  tokens, the fonts, the mark.
- Per-tenant colours (a white-label tenant) are the same mechanism one step later: the role
  tokens set on `[data-tenant]` after login.
- Five production dependencies in `ui-kit`: `reka-ui`, `@tanstack/vue-table`, `@lucide/vue`,
  `clsx`, `tailwind-merge`. All MIT or ISC.
- The component names do not change (`UiButton`, `UiField`, `UiTable`, ...), so a screen
  written against the old set keeps working; `UiModal` becomes `UiDialog`, and `UiBadge`
  splits into `UiStatus` (a state: icon, colour, word) and `UiTag` (a label).
- Token names that changed meaning were renamed, not aliased - `brand-*` is `accent-*`,
  `danger` is `crit` - and `make tokens-check` fails on the old names, so a project's own
  screen that still uses one is caught at the update rather than rendered without colour.
- `axe-core` runs in the browser suite. A component that loses an accessible name or a
  contrast ratio fails `make e2e`.

## Rejected
- **Nuxt UI / PrimeVue / Vuetify.** A styled library is a second design system to override,
  and its updates restyle the product. Headless behaviour plus our own markup does not.
- **The shadcn-vue CLI as the source.** It writes into `components/ui/` and expects to own
  it; here `ui-kit` owns it, and a project must never re-run a generator over a platform file.
- **Tokens in `.env` or a JSON file read at build time.** A colour scale, a font stack and a
  logo are CSS and files; a layer already knows how to carry both.
