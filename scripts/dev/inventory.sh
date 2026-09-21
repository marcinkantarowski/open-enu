#!/usr/bin/env bash
# =============================================================================
#  .ai/inventory.json - what already exists (.ai/platform/PLAN.md §12.3, §12.4).
# =============================================================================
#  The failure mode this addresses is REINVENTION: a second date formatter, a
#  third way to page a list, written because finding the first one meant already
#  knowing its name. The inventory is committed so it can be IN CONTEXT before
#  anything is written - a file an agent has to run a command to produce is a
#  file it will not have.
#
#  `--check` fails when the committed copy is stale, which is what makes it stay
#  true. The similarity hints it prints are advisory and never fail anything.
# =============================================================================
set -uo pipefail
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(cd "$HERE/../.." && pwd)"
. "$HERE/../lib/log.sh"

. "$ROOT/scripts/lib/compose.sh"; compose_init "$ROOT"
CO=("${COMPOSE[@]}")
API=("${CO[@]}" exec -T -u www-data api)
TARGET="$ROOT/.ai/inventory.json"
GENERATED="$(mktemp)"
HINTS="$(mktemp)"
trap 'rm -f "$GENERATED" "$HINTS"' EXIT

log_step "Inventory"

# stdout is the document, stderr the commentary: the API container has only
# backend/ mounted, so writing the file is the host's job.
if ! "${API[@]}" php bin/console app:inventory >"$GENERATED" 2>"$HINTS"; then
  log_fail "app:inventory failed" "$(tail -5 "$HINTS")"
  exit 1
fi

if [ ! -s "$GENERATED" ]; then
  # "The tool produced no output" is not "there is nothing to inventory".
  log_fail "app:inventory produced nothing" "the container could not run it; try: make builddev"
  exit 1
fi

if [ "${1:-}" = "--check" ]; then
  if ! diff -q "$TARGET" "$GENERATED" >/dev/null 2>&1; then
    log_fail ".ai/inventory.json is out of date" "run: make inventory"
    diff -u "$TARGET" "$GENERATED" | head -40
    exit 1
  fi
  log_ok "$(grep -c '^        "' "$TARGET" || true) entries, matching the code"
else
  cp "$GENERATED" "$TARGET"
  log_ok "wrote .ai/inventory.json ($(wc -c <"$TARGET" | tr -d ' ') bytes)"
fi

# Advisory, always. A hint that blocked would be wrong often enough to get
# muted, which costs more than the duplicates it would have caught.
if [ -s "$HINTS" ]; then
  cat "$HINTS"
fi
