#!/usr/bin/env bash
# =============================================================================
#  Test order - PHPUnit runs the tests in the same order on every run.
# =============================================================================
#  `executionOrder="defects"` runs the tests that failed last time first. That
#  hides a test which passes only when it runs before some other test: it fails
#  once, moves to the front, and passes from then on - on the machine that saw
#  it fail. A Sentry privacy test did exactly that, and the only run that showed
#  it was the first one. `random` is refused too: an order that changes on every
#  run fails for whoever runs next, not for whoever wrote the dependency.
#
#  See .ai/platform/lessons/a-test-order-that-learns-hides-what-it-learned.md.
#  Static - reads backend/phpunit.dist.xml, needs no running stack.
# =============================================================================
set -uo pipefail
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(cd "$HERE/../.." && pwd)"
. "$HERE/../lib/log.sh"

log_step "PHPUnit - one test order"

CONFIG="$ROOT/backend/phpunit.dist.xml"
[ -f "$CONFIG" ] \
  || die "backend/phpunit.dist.xml is missing" "the check cannot run, which is not the same as passing"

order="$(awk 'match($0, /executionOrder="[^"]*"/) { print substr($0, RSTART + 16, RLENGTH - 17); exit }' "$CONFIG")"

case ",$order," in
  *,defects,*|*,random,*|*,duration,*)
    log_fail "executionOrder=\"$order\" lets the order change between runs" \
      "use executionOrder=\"depends\" - a test that needs another to run first must fail every time"
    exit 1
    ;;
esac

log_ok "the order is fixed (executionOrder=\"${order:-default}\")"
