#!/usr/bin/env bash
# =============================================================================
#  Every endpoint has a functional test - measured, not grepped (.ai/platform/PLAN.md §12.3).
# =============================================================================
#  Runtime tracing. A listener records `_route` for every request the suite
#  makes; afterwards, `router ∖ hit` must be empty.
#
#  Grepping the tests for route names would measure whether somebody wrote the
#  name down. This measures what actually ran, which is the only thing that
#  means anything - and it notices when a test is deleted, which a grep over
#  source cannot.
# =============================================================================
set -uo pipefail
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(cd "$HERE/../.." && pwd)"
. "$HERE/../lib/log.sh"

. "$ROOT/scripts/lib/compose.sh"; compose_init "$ROOT"
CO=("${COMPOSE[@]}")
API=("${CO[@]}" exec -T -u www-data api)
TRACE=/tmp/route-trace.txt

log_step "Route coverage"

# A stale trace is worse than none: it would credit this run with routes an
# earlier one happened to hit.
"${API[@]}" rm -f "$TRACE"

# Every suite that makes HTTP requests. The unit suite does not, and including
# it would only slow this down.
if ! "${API[@]}" env ROUTE_TRACE="$TRACE" php vendor/bin/phpunit \
      --testsuite functional,security --no-output >/dev/null 2>&1; then
  log_fail "the functional and security suites must pass before coverage means anything" \
    "run: make test-functional test-security"
  exit 1
fi

"${API[@]}" php bin/console app:route:coverage "$TRACE"
