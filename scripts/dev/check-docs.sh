#!/usr/bin/env bash
# =============================================================================
#  Documentation integrity.
# =============================================================================
#  Three failures, each of which makes documentation actively worse than none:
#
#   1. A link to a file that no longer exists. An agent routed to a missing
#      guide does not fall back gracefully - it improvises.
#   2. A MODULE.md whose "Public contracts" or "Events" section disagrees with
#      the code. Those two sections are the module's published surface; if they
#      drift, every cross-module decision made from them is made on fiction.
#   3. A record on the wrong side of the platform/project line. .ai/platform/
#      is replaced by a platform update, so a project's own record left there
#      is lost - and one left outside it here is shipped to everybody.
# =============================================================================
set -uo pipefail
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(cd "$HERE/../.." && pwd)"
. "$HERE/../lib/log.sh"

fails=0

# ── 1. relative links resolve ───────────────────────────────────────────────
log_step "Markdown link integrity"

broken="$(python3 - "$ROOT" <<'PY'
import os, re, sys
root = sys.argv[1]
skip = {'.git', 'node_modules', 'vendor', '.out', '.nuxt', '.output', '.idea', 'var'}
link = re.compile(r'\[[^\]]*\]\(([^)\s]+)(?:\s+"[^"]*")?\)')
bad = []
for dirpath, dirnames, filenames in os.walk(root):
    dirnames[:] = [d for d in dirnames if d not in skip]
    for fn in filenames:
        if not fn.endswith('.md'):
            continue
        path = os.path.join(dirpath, fn)
        try:
            text = open(path, encoding='utf-8').read()
        except Exception:
            continue
        for m in link.finditer(text):
            target = m.group(1)
            if target.startswith(('http://', 'https://', 'mailto:', '#')):
                continue
            target = target.split('#', 1)[0]
            if not target:
                continue
            # Landing pages link to site ROUTES, not files. `/privacy` in
            # content/pl/terms.md is the page content/pl/privacy.md - checked in
            # the same locale, so a page linked but missing in one language fails.
            rel = os.path.relpath(dirpath, root).split(os.sep)
            if target.startswith('/') and rel[:2] == ['landing', 'content'] and len(rel) > 2:
                page = target.strip('/') or 'index'
                resolved = os.path.join(root, 'landing', 'content', rel[2], page + '.md')
            else:
                resolved = os.path.normpath(os.path.join(dirpath, target))
            if not os.path.exists(resolved):
                line = text[:m.start()].count('\n') + 1
                bad.append(f"{os.path.relpath(path, root)}:{line} -> {m.group(1)}")
for b in bad:
    print(b)
PY
)"

if [ -n "$broken" ]; then
  log_fail "$(wc -l <<<"$broken") broken link(s)" || true
  sed 's/^/      /' <<<"$broken" >&2
  fails=$((fails + 1))
else
  log_ok "every relative link resolves"
fi

# ── 2. platform and project knowledge stay apart ────────────────────────────
# .ai/platform/ ships with the platform and is replaced by an update; everything
# else under .ai/ is the project's (ADR-0023). Which side a record belongs to
# is read from .project.json, never judged.
log_step "Platform and project knowledge"

initialized="$(sed -n 's/.*"initialized"[[:space:]]*:[[:space:]]*\(true\|false\).*/\1/p' "$ROOT/.project.json" 2>/dev/null | head -1)"
if [ -z "$initialized" ]; then
  log_fail ".project.json does not say whether this is the platform or a project" \
    "it must carry \"initialized\": true or false" || true
  fails=$((fails + 1))
fi

# knowledge_set <directory> <index>: every record is a row in its OWN index.
knowledge_set() {
  local dir="$1" index="$2" f ok=1
  if [ ! -f "$index" ]; then
    log_fail "${index#"$ROOT"/} is missing" "it is the index of ${dir#"$ROOT"/}/" || true
    fails=$((fails + 1)); return
  fi
  shopt -s nullglob
  for f in "$dir"/*.md; do
    [ "$f" = "$index" ] && continue
    if ! grep -qF "$(basename "$f"))" "$index"; then
      log_fail "${f#"$ROOT"/} is not a row in ${index#"$ROOT"/}" \
        "an unindexed record is never read - add the row, or delete the file" || true
      ok=0; fails=$((fails + 1))
    fi
  done
  shopt -u nullglob
  [ "$ok" -eq 1 ] && log_ok "${dir#"$ROOT"/}/ is fully indexed"
}

for kind in adr lessons analysis; do
  knowledge_set "$ROOT/.ai/platform/$kind" "$ROOT/.ai/platform/$kind/README.md"
done
for kind in adr specs lessons analysis; do
  knowledge_set "$ROOT/.ai/$kind" "$ROOT/.ai/$kind/README.md"
done

# In the platform's own repository the project side must hold nothing but its
# indexes (and the generated inventory): whatever else is there would be handed
# to every new project as its own.
if [ "$initialized" = false ]; then
  strays="$(find "$ROOT/.ai" -path "$ROOT/.ai/platform" -prune -o -type f ! -name README.md ! -name inventory.json -print 2>/dev/null | sort)"
  if [ -n "$strays" ]; then
    while IFS= read -r f; do
      log_fail "${f#"$ROOT"/} is a project record in the platform's repository" \
        "this repository is the platform (\"initialized\": false) - it belongs under .ai/platform/" || true
      fails=$((fails + 1))
    done <<<"$strays"
  else
    log_ok ".ai/ outside platform/ is empty, as the platform ships it"
  fi
fi

# ── 3. MODULE.md matches the code ───────────────────────────────────────────
log_step "MODULE.md against the code it describes"

shopt -s nullglob
modules=("$ROOT"/backend/src/Module/*/)
shopt -u nullglob

for dir in "${modules[@]}"; do
  name="$(basename "$dir")"
  doc="$dir/MODULE.md"
  [ -f "$doc" ] || continue
  ok=1

  # Every class in Contract/ and Event/ must be named in MODULE.md: they are the
  # surface other modules are allowed to couple to, so an undocumented one is a
  # coupling nobody agreed to.
  for section in Contract Event; do
    shopt -s nullglob
    for f in "$dir$section"/*.php; do
      cls="$(basename "$f" .php)"
      if ! grep -qF "$cls" "$doc"; then
        log_fail "$name: $section/$cls.php is not mentioned in MODULE.md" \
          "other modules may couple to it, so it must be documented as published surface" || true
        ok=0
        fails=$((fails + 1))
      fi
    done
    shopt -u nullglob
  done

  [ "$ok" -eq 1 ] && log_ok "$name"
done

echo
if [ "$fails" -eq 0 ]; then
  log_ok "documentation matches the code"
  exit 0
fi
log_fail "$fails documentation problem(s)"
exit 1
