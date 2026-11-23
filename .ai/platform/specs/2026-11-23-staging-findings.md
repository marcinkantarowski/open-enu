# Staging findings: rate limit, members, operator console, dates

Found by clicking through a project's staging as several users (2026-11-22).

## Rate limit on the production routers, not the edge entrypoint

The first page load after every staging deploy was blank. The edge applied
`rate-limit@file` (100/s, burst 200) to every request on `websecure`; staging is
the dev stack, and Vite serves a first load as several hundred module requests -
from one VPN address the whole team shares. Part of them got 429 and the app's
entry import failed.

The limit is now named by each production router (`compose.prod.yml`), and
`make prod-check` fails on a public production router without it - a project's
own routers included. Staging routers sit behind their IP allowlist instead.

## Controls a member would be refused

`GET /api/members` carries `manageable` per row, from `AccessGuard` - the rule
that refuses the change. The page shows edit/suspend only there: an admin no
longer sees a pencil on the owner.

## Operator console

The tenant page links to Flags and Audit with `?tenant=`; both arrive filled and
name the tenant (`useTenantName`). Still a field, not a picker over every tenant.
Audit rows link their tenant.

## Dates and names

`useDateTime()` (ui-kit) formats an ISO moment in the reader's locale; both
audit views use it. The tenant audit names the actor when the reader may list
members.

## Polish without a guessed gender

Three strings addressed the reader as "przerwałeś"/"patrzyłeś"/"prosiłeś".
Rephrased, and `make i18n-check` fails on the 2nd-person past tense in any
Polish catalogue.

## A role without settings.view (second pass)

- The error page told a 403 "something went wrong, try again in a moment" -
  advice no retry follows. 403 now has its own words: no access, ask an
  administrator.
- Settings was offered only with `settings.view`, the workspace tab - yet every
  session has its profile and often more tabs. The menu entry has no
  permission now, and `/settings` without `settings.view` redirects to the
  first tab the session may open (`settingsHome()`).

`e2e/tests/access.spec.ts`: a role reading one thing opens Settings on its
profile and gets "no access" on the audit page.

## Not changed

- Audit action keys stay as keys: translating them is a per-command contract.
- The operator sees the Example flag even with `DEMO_CONTENT=false`: its routes
  still answer, and a kill switch the operator cannot see is the failure flags
  exist to prevent.
