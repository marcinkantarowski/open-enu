# Search as you type - prefixes, accents, a search box in the shell

**Status:** done · **Date:** 2026-11-22

## Problem

`GET /api/search` existed, but nothing in the apps called it, and its query did not suit a
search box. `websearch_to_tsquery` with the `simple` configuration matches whole words only:
"kowal" does not find "Kowalska", "zajac" does not find "Zając". And the excerpt, cut around
the match, dropped the first words of a short record - "Jan Kowalski jan@…" came back as
"Kowalski jan@…", without the name.

## Approach

- **Kernel, query.** Every word typed is a prefix and all must match, in any order (`'kowal':*
  & 'jan':*`). Each word is quoted, so the parser keeps an e-mail or "12-345" whole; the
  characters `to_tsquery` reads as operators split words instead of breaking the query.
- **Kernel, accents.** A text search configuration `open_enu_search`: `simple` with `unaccent`
  in front for words with non-ASCII letters, created by kernel migration
  `Version20200101000002`, which also re-parses every stored document. `content` and the
  excerpt keep the letters as typed.
- **Kernel, excerpt.** Up to 160 characters the record is returned whole with every match
  marked (`HighlightAll`); longer ones are cut around the match as before.
- **Frontend.** `GlobalSearch` in the shell's top bar: `/` or Ctrl K, results grouped, arrows,
  Enter, Esc, a combobox to assistive technology. The API knows a class and an id, not a page,
  so the module's frontend layer says where a hit opens in `search.ts`
  (`defineSearchTargets`); a hit nobody claims is left out. The excerpt is split on its `<b>`
  markers and rendered as text, never `v-html`.

## Rejected

- **`pg_trgm` similarity.** Tolerates typos, but ranks "Kowalczyk" next to "Kowalski" for
  "kowalski" and needs its own index; prefix matching is what a search box needs first.
- **Unaccenting in PHP.** Documents would have to be stored unaccented, and the excerpt would
  lose the letters.
- **Routes in `search.php`.** The backend would learn the frontend's URLs.

## Surface

- API: `GET /api/search?q=` (same shape; matching and excerpts change)
- UI: the top bar of `frontend`; `frontend/app/modules/*/search.ts`
- Checks: `make module-check` fails on an entity in `search.php` with no `search.ts` target

## Tests

- [x] `tests/Search/SearchTest`: prefixes in any order, accents and case, the whole short
      record and the cut long one, operators as typos
- [x] `e2e/tests/search.spec.ts`: `/`, two prefixes, the marked match, Enter opens the page

## Changelog

- 2026-11-22 - spec and implementation
