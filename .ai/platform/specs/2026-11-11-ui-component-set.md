# The UI component set, and a project's theme

Decision: [ADR-0024](../adr/0024-ui-components-on-reka-styled-by-role-tokens.md).

## Problem

`ui-kit` has twelve plain primitives and no way for a project to look like itself without
editing platform files. The first product needs a menu, tabs, a drawer, a sortable paged
table, statuses that are always a word, and its own colours, fonts and logo - all of it
keyboard- and screen-reader-correct.

## Approach

**Tokens** - `ui-kit/app/assets/css/main.css` defines an accent scale `accent-50`..`accent-950`
and role tokens over it, with neutral values:

| Token | Use |
|---|---|
| `canvas`, `surface`, `surface-subtle`, `selected` | backgrounds |
| `nav`, `nav-title`, `nav-fg`, `nav-fg-active`, `nav-active`, `nav-hover`, `nav-border` | the navigation column, light or dark as the theme decides |
| `fg`, `fg-muted`, `link` | text |
| `border`, `border-input` | lines; an input border keeps 3:1 against white |
| `accent`, `accent-hover`, `accent-active`, `accent-fg`, `focus` | the one action colour |
| `ok`, `warn`, `crit`, `info` + `-soft` + `-border` | statuses - never re-coloured by a tenant |
| `chart-1` .. `chart-8` | categorical series |

plus `control-sm/md/lg` heights, `--radius-control`, `--radius-card`, `--shadow-card`,
`--shadow-raised`, `--ease-standard`, `--font-sans` / `--font-mono`. Removed and migrated
everywhere, not aliased: `brand-*` (now `accent-*`), `surface-muted` (`canvas`),
`surface-sunken` (`surface-subtle`), `danger`, `warning`, `success` (`crit`, `warn`, `ok`).
`make tokens-check` fails on any of those, and on a raw Tailwind palette colour.

**Theme layer** - each app's `nuxt.config.ts` extends `../theme` first when it has a
`nuxt.config.ts`. The platform ships `theme/README.md` only; the image copies `theme/`.

**Components** - `ui-kit/app/components/ui/`, `Ui` prefix:

| Component | On | Replaces |
|---|---|---|
| `UiButton` (primary, secondary, tertiary, ghost, danger; sm/md/lg; icon-only) | - | `UiButton` |
| `UiField`, `UiInput`, `UiTextarea` | - | same |
| `UiSelect` | Reka `Select` | native `<select>` |
| `UiCheckbox`, `UiRadioGroup`, `UiSwitch` | Reka | - |
| `UiStatus` (ok, warn, crit, neutral, info - icon + colour + word) | - | `UiBadge` (states) |
| `UiTag` (neutral, accent - a label, no icon) | - | `UiBadge` (labels) |
| `UiAlert` | - | same |
| `UiDialog`, `UiDrawer` | Reka `Dialog` | `UiModal` |
| `UiTabs` | Reka `Tabs` | - |
| `UiTooltip`, `UiDropdownMenu` | Reka | - |
| `UiTable` (sortable, server mode) | TanStack Table | `UiTable` |
| `UiPagination` | Reka `Pagination` | - |
| `UiCard`, `UiEmptyState`, `UiSpinner`, `UiSkeleton`, `UiProgressBar` | - | same |

`ToastHost` keeps its API on `useToast()`. The tenant shell gets a skip link, a 256 px
navigation on the `nav` roles with a Lucide icon per entry (`NavigationItem.icon` is now a
component) and a 56 px top bar. `BrandMark` gains a `nav` tone.

## Tests

- `make typecheck`, `make dead-code`, `make i18n-check`, `make tokens-check` green; every screen
  migrated. `make selftest` proves `tokens-check` fails on a palette colour and on a removed token.
- `e2e/tests/a11y.spec.ts`: axe-core on login, the dashboard, the Example list with its
  dialog open, and the operator console - no violations at WCAG 2.1 AA.
- The existing browser suite green; the two tenant-switch steps pick from a combobox instead
  of `selectOption`, which only drives a native `<select>`.

## Rejected

See the ADR. Also: keeping `UiModal`/`UiBadge` as aliases - two names for one thing is how a
codebase ends up with both.

## Changelog

- 2026-11-11 - written.
