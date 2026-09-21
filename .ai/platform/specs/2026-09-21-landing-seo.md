# Landing: ready to be found

**Status:** accepted · **Date:** 2026-09-21

## Problem

The landing site is the one public, indexable surface of a project, and it shipped with
nothing a search engine or an LLM crawler needs: no `robots.txt`, no sitemap, no canonical
URL, no Open Graph tags, no structured data, no `llms.txt`.

Worse, it could not be fixed by adding tags. Both languages were served **at the same URL**,
chosen by cookie and `Accept-Language` (`strategy: 'no_prefix'`, inherited from the ui-kit
layer where it is right for the two SPAs). A crawler sends neither, so the Polish site was
invisible: one URL cannot be indexed twice, and `hreflang` has nothing to point at.

And a staging server is the same code on a public hostname. Nothing stopped it from being
indexed next to production.

## Approach

**One URL per language.** `landing` overrides the i18n strategy to `prefix_except_default`:
English at `/contact`, Polish at `/pl/contact`. The SPAs are untouched. Content files do not
move - `content/pl/contact.md` is still the Polish `/contact`; only the URL gained a prefix.
Every internal link goes through `localePath()`, including links written in markdown bodies.

**Everything generated from the content, nothing maintained by hand.** No new dependency:
three Nitro routes read the same `@nuxt/content` collections the pages do.

| Path | What it is |
|---|---|
| `/robots.txt` | Allow + sitemap link when indexable; `Disallow: /` otherwise |
| `/sitemap.xml` | Every page in every locale, with `xhtml:link` hreflang alternates and `x-default` |
| `/llms.txt` | The llmstxt.org index: name, summary, and every page with its description |

**Indexable is opt-in and derives from `APP_STAGE`.** `envgen` writes
`NUXT_PUBLIC_INDEXABLE=true` only for `production`. Anywhere else: `robots.txt` disallows,
every response carries `X-Robots-Tag: noindex, nofollow`, and the pages carry the meta tag.
The default in code is `false`, so a missing variable fails closed.

**One composable owns the head**, `usePageSeo(page)`: title, description, canonical
(absolute, no query, no trailing slash), hreflang alternates, Open Graph, Twitter card, and
JSON-LD (`WebSite` + `Organization` on the home page, `WebPage` elsewhere, `FAQPage` when a
page has `faqs`). A page template that forgets to call it has no SEO at all, which is why
there are exactly two templates.

**Authoring rules are a check, not advice.** `make seo-check` (in `make check`) fails on a
page whose title or description is missing, too long, too short or duplicated; on an `h1` in
a body (the template renders the title as the only one); on an image with no alt text; and
on a filename that would make a bad URL. The rules are listed in
`.ai/platform/docs/landing.md`.

## Rejected

- **`@nuxtjs/seo` / `@nuxtjs/sitemap` / `@nuxtjs/robots`.** Six modules to do what ninety
  lines do here, and adding a production dependency is an Ask First.
- **Keeping one URL and serving by `Accept-Language`.** It is what made the Polish site
  unindexable in the first place.
- **A static `public/robots.txt`.** It cannot know the domain or the stage, and a static
  "allow" on a staging server is precisely the failure this closes.

## Not done here

- `www.${DOMAIN}` still answers 200 with the same pages. The canonical URL points at the apex
  host, which is enough for search engines; a 301 belongs in the Traefik labels of all three
  compose files and is a separate change.
- `public/og.png` is a plain brand-coloured card. A project replaces it.

## Tests that ship with it

- `make smoke` fetches `/robots.txt`, `/sitemap.xml` and `/llms.txt` and asserts content.
- `make selftest` breaks a description and proves `seo-check` fails.

## Changelog

- 2026-09-21 - written and implemented.
