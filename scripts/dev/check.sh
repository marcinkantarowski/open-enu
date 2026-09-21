#!/usr/bin/env bash
# =============================================================================
#  make check - the inner loop.
# =============================================================================
#  Budget: 60 seconds. That number is a design constraint, not an aspiration.
#  A check an agent runs after every edit has to be fast enough to actually be
#  run after every edit; past about a minute it gets skipped, and a guardrail
#  nobody runs is a guardrail that does not exist.
#
#  So this is the fast subset. `make ci` is the full gate.
# =============================================================================
set -uo pipefail
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(cd "$HERE/../.." && pwd)"
. "$HERE/../lib/log.sh"

started=$(date +%s)
fails=0
run() { # <label> <command...>
  local label="$1"; shift
  if "$@" >/tmp/check.$$ 2>&1; then
    log_ok "$label"
  else
    log_fail "$label" || true
    sed 's/^/      /' /tmp/check.$$ | tail -25 >&2
    fails=$((fails + 1))
  fi
  rm -f /tmp/check.$$
}

. "$ROOT/scripts/lib/compose.sh"; compose_init "$ROOT"
CO=("${COMPOSE[@]}")
API=("${CO[@]}" exec -T -u www-data api)

log_step "Fast checks"

# Host-side and instant.
run "module structure"  "$HERE/check-modules.sh"
run "documentation"     "$HERE/check-docs.sh"
run "translations"      "$HERE/check-i18n.sh"
run "context budgets"   "$HERE/agents-budget.sh"
run "typography"        "$HERE/check-typography.sh"
run "landing SEO"       "$HERE/check-landing-seo.sh"
run "env completeness"  "$ROOT/scripts/lib/envgen.sh" check

# Container-side. Skipped rather than failed when the stack is down: `make check`
# must stay useful while editing with nothing running.
if "${CO[@]}" ps --status running --services 2>/dev/null | grep -qx api; then
  run "static analysis"   "${API[@]}" php vendor/bin/phpstan analyse --no-progress
  run "kernel tests"      "${API[@]}" php vendor/bin/phpunit --testsuite kernel
  run "module unit tests" "${API[@]}" php vendor/bin/phpunit --testsuite unit
else
  log_skip "static analysis and tests (the stack is not running - 'make up' first)"
fi

elapsed=$(( $(date +%s) - started ))
echo
if [ "$fails" -gt 0 ]; then
  log_fail "$fails check(s) failed in ${elapsed}s"
  exit 1
fi

if [ "$elapsed" -gt 60 ]; then
  log_warn "passed, but took ${elapsed}s - over the 60s budget"
  log_info "a slow inner loop stops being run; treat this as a bug in the harness"
else
  log_ok "all checks passed in ${elapsed}s"
fi
