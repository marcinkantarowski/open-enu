#!/usr/bin/env bash
# Install dependencies INSIDE the containers, so versions match what runs in
# production rather than whatever the host happens to have.
set -uo pipefail
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(cd "$HERE/../.." && pwd)"
. "$HERE/../lib/log.sh"
. "$ROOT/scripts/lib/compose.sh"; compose_init "$ROOT"
CO=("${COMPOSE[@]}")

log_step "PHP dependencies (in the api container)"
# As www-data (remapped to the host uid), not root - otherwise every install
# leaves root-owned files in the bind-mounted source. COMPOSER_HOME must be
# writable for that user.
if "${CO[@]}" exec -T -u www-data -e COMPOSER_HOME=/tmp/composer api \
     composer install --no-interaction --no-progress 2>&1 | tail -3; then
  log_ok "composer install"
else
  log_fail "composer install failed" "inspect: make logs-api"; exit 1
fi

log_step "Node dependencies (npm workspaces)"
# One install at the workspace root covers ui-kit and all three apps, and
# symlinks @open-enu/ui-kit into node_modules so `extends` resolves it as a
# package rather than a relative path.
#
# `run --rm --no-deps` rather than `exec`: on a fresh checkout the Nuxt
# containers have no `nuxt` binary yet, so there is nothing to exec into. The
# image entrypoint installs on first boot anyway; this target is the explicit
# re-install after a package.json change.
if "${CO[@]}" run --rm --no-deps --entrypoint sh frontend -c 'npm install --no-audit --no-fund' 2>&1 | tail -4; then
  log_ok "npm install (root workspace)"
else
  log_fail "npm install failed" "inspect: make logs-ui"; exit 1
fi

log_step "Restarting the Nuxt apps so they pick the new dependencies up"
"${CO[@]}" restart frontend manager landing >/dev/null 2>&1
log_ok "frontend, manager, landing restarted"
