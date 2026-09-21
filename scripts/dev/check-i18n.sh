#!/usr/bin/env bash
# =============================================================================
#  Translation completeness (ADR-0020).
# =============================================================================
#  Two locales ship from day one so that adding a third is a file rather than a
#  project. That only holds if they stay in step: a key added to en and forgotten
#  in pl surfaces as a raw translation key in the UI, in production, to exactly
#  the users who read that language.
#
#  Backend catalogues use Symfony's `<domain>.<locale>.json` under
#  `<module>/i18n/`; frontend ones use Nuxt's `<locale>.json` under
#  `<layer>/i18n/locales/`, which is where @nuxtjs/i18n looks by default. Each
#  convention is native to its side, so the prefix is a parameter rather than a
#  thing to unify.
# =============================================================================
set -uo pipefail
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(cd "$HERE/../.." && pwd)"
. "$HERE/../lib/log.sh"

LOCALES=(en pl)
BASE="${LOCALES[0]}"
fails=0

# compare <label> <dir> <prefix>
compare() {
  local label="$1" dir="$2" prefix="$3"
  [ -f "$dir/${prefix}${BASE}.json" ] || return 0

  local loc
  for loc in "${LOCALES[@]:1}"; do
    local other="$dir/${prefix}${loc}.json"
    if [ ! -f "$other" ]; then
      log_fail "$label: ${prefix}${loc}.json is missing" "every locale file needs its counterpart" || true
      fails=$((fails + 1))
      continue
    fi

    local report
    report="$(python3 - "$dir/${prefix}${BASE}.json" "$other" <<'PY'
import json, sys

def keys(path):
    try:
        with open(path, encoding='utf-8') as fh:
            data = json.load(fh)
    except Exception as exc:
        print(f"cannot parse {path}: {exc}")
        sys.exit(0)
    if not isinstance(data, dict):
        print(f"{path} must contain a JSON object")
        sys.exit(0)
    return set(data)

a, b = keys(sys.argv[1]), keys(sys.argv[2])
for k in sorted(a - b):
    print(f"only in {sys.argv[1].rsplit('/', 1)[-1]}: {k}")
for k in sorted(b - a):
    print(f"only in {sys.argv[2].rsplit('/', 1)[-1]}: {k}")
PY
)"

    if [ -n "$report" ]; then
      log_fail "$label: ${prefix}${BASE}.json and ${prefix}${loc}.json disagree" || true
      sed 's/^/      /' <<<"$report" >&2
      fails=$((fails + 1))
    else
      log_ok "$label (${BASE} ↔ ${loc})"
    fi
  done
}

log_step "Translation key parity"

shopt -s nullglob
for d in "$ROOT"/backend/src/Module/*/i18n; do
  compare "backend/$(basename "$(dirname "$d")")" "$d" "messages."
done
for d in "$ROOT"/frontend/app/modules/*/i18n/locales; do
  compare "frontend/$(basename "$(dirname "$(dirname "$d")")")" "$d" ""
done
for app in ui-kit frontend manager landing; do
  d="$ROOT/$app/i18n/locales"
  [ -d "$d" ] && compare "$app" "$d" ""
done
shopt -u nullglob

# Landing content is one markdown file per page PER LOCALE, and the site has no
# fallback to English (landing/app/composables/useContentPage.ts): a page that
# exists in one directory is a 404 in the other language.
content="$ROOT/landing/content"
if [ -d "$content/$BASE" ]; then
  for loc in "${LOCALES[@]:1}"; do
    report="$(diff <(cd "$content/$BASE" 2>/dev/null && find . -name '*.md' | sort) \
                   <(cd "$content/$loc" 2>/dev/null && find . -name '*.md' | sort) \
              | sed -n "s|^< \./|only in $BASE: |p; s|^> \./|only in $loc: |p")"
    if [ -n "$report" ]; then
      log_fail "landing/content: $BASE and $loc hold different pages" || true
      sed 's/^/      /' <<<"$report" >&2
      fails=$((fails + 1))
    else
      log_ok "landing/content (${BASE} ↔ ${loc})"
    fi
  done
fi

echo
if [ "$fails" -eq 0 ]; then
  log_ok "every locale file has the same keys as its counterparts"
  exit 0
fi
log_fail "$fails locale mismatch(es)"
exit 1
