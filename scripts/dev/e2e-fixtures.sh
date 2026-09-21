#!/usr/bin/env bash
# =============================================================================
#  The accounts the browser suite signs in as.
# =============================================================================
#  Deliberately created by console commands rather than by an SQL dump or a
#  Playwright global-setup that pokes the API: both of those would need to know
#  how a password is hashed and how a tenant is activated, which is exactly the
#  knowledge that belongs behind `app:user:create`.
#
#  Everything here is idempotent, so `make e2e` can run it every time without
#  accumulating state - and a developer can run it once to get an account to log
#  in with by hand.
#
#  Two workspaces for one person, on purpose: without a second membership the
#  tenant switcher never renders, and the switch spec would assert on something
#  that is not there.
# =============================================================================
set -uo pipefail
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(cd "$HERE/../.." && pwd)"
. "$HERE/../lib/log.sh"

. "$ROOT/scripts/lib/compose.sh"; compose_init "$ROOT"
CO=("${COMPOSE[@]}")
console() { "${CO[@]}" exec -T -u www-data api php bin/console "$@"; }

# Must match e2e/fixtures/accounts.ts. Changing either without the other makes
# the suite fail at the login form, which reads like a broken login page.
OWNER_EMAIL='e2e-owner@example.test'
OWNER_PASSWORD='e2e-correct-horse-battery'
OPERATOR_EMAIL='e2e-operator@example.test'
OPERATOR_PASSWORD='e2e-operator-correct-horse-battery'

log_step "End-to-end fixtures"

if ! console list >/dev/null 2>&1; then
  log_fail "the API container is not answering" "run: make up && make wait"
  exit 1
fi

console app:user:create "$OWNER_EMAIL" \
  --password="$OWNER_PASSWORD" \
  --name='E2E Owner' \
  --tenant='E2E Primary' --slug='e2e-primary' --role=owner --seed >/dev/null \
  || { log_fail "could not create the primary workspace"; exit 1; }
log_ok "E2E Primary - $OWNER_EMAIL (owner)"

console app:user:create "$OWNER_EMAIL" \
  --password="$OWNER_PASSWORD" \
  --name='E2E Owner' \
  --tenant='E2E Second' --slug='e2e-second' --role=owner --seed >/dev/null \
  || { log_fail "could not create the second workspace"; exit 1; }
log_ok "E2E Second - same person, second membership"

# `--seed` above put each module's example rows in both workspaces: the conflict
# and realtime specs need a row to rename before a browser opens.
log_ok "example projects seeded in both workspaces"

# Already-exists is a success here: the command refuses a duplicate address, and
# on the second run that is exactly what we want it to do.
if console app:manager:create "$OPERATOR_EMAIL" --password="$OPERATOR_PASSWORD" --name='E2E Operator' >/dev/null 2>&1; then
  log_ok "operator created - $OPERATOR_EMAIL"
else
  log_skip "operator already exists - $OPERATOR_EMAIL"
fi
