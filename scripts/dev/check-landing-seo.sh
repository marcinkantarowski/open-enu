#!/usr/bin/env bash
# =============================================================================
#  Landing pages - the authoring rules a search engine holds them to.
# =============================================================================
#  The templates take care of everything structural: canonical URLs, hreflang,
#  Open Graph, JSON-LD, the sitemap. What they cannot supply is the WORDS, and
#  the words are where a page made by a person or an agent in a hurry goes
#  wrong: no description, a title that is cut off in the results, two pages
#  with the same one, a second <h1>, an image nothing can read.
#
#  Rules and reasons: .ai/platform/docs/landing.md. Static - reads the markdown,
#  needs no running stack.
# =============================================================================
set -uo pipefail
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(cd "$HERE/../.." && pwd)"
. "$HERE/../lib/log.sh"

log_step "Landing pages - SEO authoring rules"

CONTENT="$ROOT/landing/content"
[ -d "$CONTENT" ] || die "landing/content is missing" "the check cannot run, which is not the same as passing"

problems="$(python3 - "$CONTENT" <<'PY'
import os, re, sys
root = sys.argv[1]
TITLE_MAX, DESC_MIN, DESC_MAX = 60, 50, 160
SLUG = re.compile(r'^[a-z0-9]+(-[a-z0-9]+)*\.md$')
bad, pages = [], 0

def scalar(front, key):
    m = re.search(rf'^{key}:[ \t]*(.*)$', front, re.M)
    return m.group(1).strip().strip('"\'') if m else None

for locale in sorted(os.listdir(root)):
    seen_title, seen_desc = {}, {}
    ldir = os.path.join(root, locale)
    if not os.path.isdir(ldir):
        continue
    for dirpath, _, files in os.walk(ldir):
        for fn in sorted(files):
            if not fn.endswith('.md'):
                continue
            pages += 1
            path = os.path.join(dirpath, fn)
            rel = os.path.relpath(path, os.path.dirname(root))
            text = open(path, encoding='utf-8').read()
            m = re.match(r'^---\n(.*?)\n---\n?(.*)$', text, re.S)
            if not m:
                bad.append(f'{rel}: no frontmatter - a page needs at least title and description'); continue
            front, body = m.group(1), m.group(2)

            if not SLUG.match(fn):
                bad.append(f'{rel}: the filename is the URL - lowercase words joined by hyphens, nothing else')

            title, desc = scalar(front, 'title'), scalar(front, 'description')
            if not title:
                bad.append(f'{rel}: title is missing')
            elif len(title) > TITLE_MAX:
                bad.append(f'{rel}: title is {len(title)} characters; over {TITLE_MAX} it is cut off in search results')
            if not desc:
                bad.append(f'{rel}: description is missing - it is the snippet under the link, and the llms.txt entry')
            elif not DESC_MIN <= len(desc) <= DESC_MAX:
                bad.append(f'{rel}: description is {len(desc)} characters; keep it between {DESC_MIN} and {DESC_MAX}')

            for value, seen, what in ((title, seen_title, 'title'), (desc, seen_desc, 'description')):
                if value and value in seen:
                    bad.append(f'{rel}: same {what} as {seen[value]} - every page needs its own')
                elif value:
                    seen[value] = rel

            prose = re.sub(r'```.*?```', '', body, flags=re.S)
            if re.search(r'^# ', prose, re.M):
                bad.append(f'{rel}: an "# " heading in the body - the template renders the title as the only h1; start at "## "')
            first = re.search(r'^(#{2,6}) ', prose, re.M)
            if first and len(first.group(1)) > 2:
                bad.append(f'{rel}: the first heading is h{len(first.group(1))} - headings start at "## " and do not skip a level')
            for img in re.finditer(r'!\[([^\]]*)\]\(', prose):
                if not img.group(1).strip():
                    bad.append(f'{rel}: an image with no alt text - write what it shows, or leave the image out')

print(pages)
for b in bad:
    print(b)
PY
)"
status=$?
[ "$status" -eq 0 ] || die "the SEO check could not run (python exited $status)"

pages="$(head -1 <<<"$problems")"
problems="$(tail -n +2 <<<"$problems")"

# Zero pages read is a broken check, not a clean site.
[ "${pages:-0}" -gt 0 ] || die "no landing pages were found under landing/content" "nothing was checked"

if [ -n "$problems" ]; then
  log_fail "$(wc -l <<<"$problems" | tr -d ' ') problem(s) in $pages page(s)" "rules: .ai/platform/docs/landing.md" || true
  sed 's/^/      /' <<<"$problems" >&2
  exit 1
fi
log_ok "$pages pages follow the authoring rules"
