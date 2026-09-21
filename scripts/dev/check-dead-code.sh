#!/usr/bin/env bash
# =============================================================================
#  Dead code (.ai/platform/PLAN.md §12.3) - blocking for TypeScript, advisory for PHP.
# =============================================================================
#  The failure mode is ABANDONED SCAFFOLDING: a composable replaced but not
#  deleted, a service written for an approach that was dropped, a dependency
#  added and then not used. None of it breaks anything, which is precisely why
#  it survives - and why the next agent reads it as an example to follow.
#
#  TypeScript: knip, blocking. The Nuxt conventions it cannot see are declared
#  once in knip.config.ts, each with the reason attached.
#
#  PHP: container introspection, ADVISORY. A class can be reached in ways no
#  static view shows - a Doctrine type registered by name, a migration, a
#  fixture referenced as a string - so a blocking version would be wrong often
#  enough to get muted. It is printed and never fails the build (§12.3).
# =============================================================================
set -uo pipefail
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(cd "$HERE/../.." && pwd)"
. "$HERE/../lib/log.sh"

. "$ROOT/scripts/lib/compose.sh"; compose_init "$ROOT"
CO=("${COMPOSE[@]}")
rc=0

# ── TypeScript ──────────────────────────────────────────────────────────────
log_step "Dead code - TypeScript (knip)"

out="$(mktemp)"; trap 'rm -f "$out"' EXIT

# `--no-config-hints`: knip suggests tightening patterns that match nothing yet,
# and several of ours describe a convention a module may not use today. The
# suggestions are about the config, never about the code.
if "${CO[@]}" run --rm --no-deps --entrypoint sh frontend \
     -c 'npx knip --no-progress --no-config-hints' >"$out" 2>&1; then
  log_ok "no unused files, exports or dependencies"
else
  log_fail "knip found dead TypeScript" \
    "delete it, or - if a framework reaches it by convention - declare that convention in knip.config.ts"
  grep -v '^npm notice' "$out" | grep -v '^ Container' | head -40
  rc=1
fi

# ── PHP ─────────────────────────────────────────────────────────────────────
log_step "Dead code - PHP (advisory)"

container="$(mktemp)"; router="$(mktemp)"
trap 'rm -f "$out" "$container" "$router"' EXIT

"${CO[@]}" exec -T -u www-data api php bin/console debug:container --format=json >"$container" 2>/dev/null
"${CO[@]}" exec -T -u www-data api php bin/console debug:router --format=json >"$router" 2>/dev/null

if [ ! -s "$container" ]; then
  # "The tool produced no output" is not "the code is all alive".
  log_warn "could not introspect the container - skipped (is the stack up?)"
  exit $rc
fi

python3 "$HERE/lib/dead-php.py" "$ROOT/backend/src" "$container" "$router" || rc=$?
exit $rc
