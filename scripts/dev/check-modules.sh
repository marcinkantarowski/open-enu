#!/usr/bin/env bash
# =============================================================================
#  Structural checks on every module.
# =============================================================================
#  Discovery is forgiving by design - it skips what it does not recognise - so a
#  half-made module loads silently and behaves oddly later. This is the counter:
#  it refuses to let a module exist in a shape the conventions do not describe.
# =============================================================================
set -uo pipefail
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(cd "$HERE/../.." && pwd)"
. "$HERE/../lib/log.sh"

MODULES_DIR="$ROOT/backend/src/Module"
AGENTS="$ROOT/AGENTS.md"
fails=0
bad() { log_fail "$@" || true; fails=$((fails + 1)); }

# Every directory a module may contain. An invented one means a convention was
# guessed at rather than followed, and the next module will guess differently.
ALLOWED_DIRS="Contract Controller Command Console Dto Entity Repository Service Setup Event Listener Message Handler Security Acl Migrations Fixtures Tests i18n templates openapi"

[ -d "$MODULES_DIR" ] || { log_skip "no modules yet"; exit 0; }

shopt -s nullglob
modules=("$MODULES_DIR"/*/)
shopt -u nullglob

if [ ${#modules[@]} -eq 0 ]; then
  log_skip "no modules yet"
  exit 0
fi

log_step "Module structure (${#modules[@]})"

for dir in "${modules[@]}"; do
  name="$(basename "$dir")"
  rel="backend/src/Module/$name"
  ok=1

  # ── the two files that make a directory a module ──────────────────────────
  [ -f "$dir/module.yaml" ] || { bad "$rel is missing module.yaml" "without it the kernel does not see this directory at all"; ok=0; }
  [ -f "$dir/MODULE.md" ]   || { bad "$rel is missing MODULE.md" "every module documents what it owns; run: make module NAME=$name"; ok=0; }

  if [ -f "$dir/module.yaml" ]; then
    declared="$(sed -n 's/^name:[[:space:]]*//p' "$dir/module.yaml" | head -1 | tr -d '\r')"
    if [ "$declared" != "$name" ]; then
      bad "$rel/module.yaml declares name '$declared' but lives in '$name'" \
          "the directory name IS the PSR-4 namespace segment; they must match"
      ok=0
    fi
  fi

  # ── no invented directories ───────────────────────────────────────────────
  for sub in "$dir"*/; do
    subname="$(basename "$sub")"
    if ! grep -qw -- "$subname" <<<"$ALLOWED_DIRS"; then
      bad "$rel/$subname/ is not a recognised module directory" \
          "allowed: $ALLOWED_DIRS" \
          "if this genuinely needs a new kind of directory, that is an Ask First - it changes the convention for every module"
      ok=0
    fi
  done

  # ── permissions declared ──────────────────────────────────────────────────
  if [ ! -f "$dir/Acl/permissions.php" ]; then
    bad "$rel has no Acl/permissions.php" \
        "a module with endpoints needs permissions; an empty array is a valid answer, silence is not"
    ok=0
  fi

  # ── both locales present (ADR-0020) ───────────────────────────────────────
  for loc in en pl; do
    # Symfony's naming, not Nuxt's. Required rather than optional: check-i18n
    # skips a directory whose base catalogue is absent, so a mis-named file
    # would otherwise pass both checks while translating nothing.
    [ -f "$dir/i18n/messages.$loc.json" ] || { bad "$rel/i18n/messages.$loc.json is missing" "both locales ship from the start (ADR-0020); Symfony naming is <domain>.<locale>.json"; ok=0; }
  done

  # ── the harness knows about it ────────────────────────────────────────────
  if [ -f "$AGENTS" ] && ! grep -qF "$rel/MODULE.md" "$AGENTS"; then
    bad "$name has no Task Router row in AGENTS.md" \
        "an agent routed by that table will never find this module" \
        "add: | $name - ... | \`$rel/MODULE.md\` |"
    ok=0
  fi

  [ "$ok" -eq 1 ] && log_ok "$name"
done

echo
if [ "$fails" -eq 0 ]; then
  log_ok "every module is structurally complete"
  exit 0
fi
log_fail "$fails problem(s) across the modules above"
exit 1
