#!/usr/bin/env bash
# Install dependencies INSIDE the containers, so versions match what runs in
# production rather than whatever the host happens to have.
set -uo pipefail
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(cd "$HERE/../.." && pwd)"
. "$HERE/../lib/log.sh"
. "$ROOT/scripts/lib/compose.sh"; compose_init "$ROOT"
CO=("${COMPOSE[@]}")

# Runs a command quietly: the last few lines when it succeeds, the last forty
# when it fails. A bare `| tail -4` cut the actual error off and left only
# "A complete log of this run can be found in..." - a path inside a container
# that `run --rm` has already deleted.
quiet() {
  local out rc
  out="$(mktemp)"
  "$@" >"$out" 2>&1; rc=$?
  if [ "$rc" -eq 0 ]; then tail -4 "$out"; else tail -40 "$out"; fi
  rm -f "$out"
  return "$rc"
}

log_step "PHP dependencies (in the api container)"
# As www-data (remapped to the host uid), not root - otherwise every install
# leaves root-owned files in the bind-mounted source. COMPOSER_HOME must be
# writable for that user.
if quiet "${CO[@]}" exec -T -u www-data -e COMPOSER_HOME=/tmp/composer api \
     composer install --no-interaction --no-progress; then
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
#
# Under the entrypoint's own lock. `up` has just started the three Nuxt
# containers, and on a fresh checkout their entrypoints are installing into
# this same node_modules right now; `--entrypoint sh` bypasses that lock, so two
# installs wrote one tree at once and left it half-deleted (ENOTEMPTY, then a
# postinstall that cannot find a package's own files). Waiting on the lock makes
# this install run after theirs, and the stamp stops them repeating it.
INSTALL='mkdir -p /app/node_modules \
  && flock /app/node_modules/.install.lock sh -c "cd /app && npm install --no-audit --no-fund && touch /app/node_modules/.install-stamp"'
if quiet "${CO[@]}" run --rm --no-deps --entrypoint sh frontend -c "$INSTALL"; then
  log_ok "npm install (root workspace)"
else
  log_fail "npm install failed" "inspect: make logs-ui"; exit 1
fi

log_step "Restarting the Nuxt apps so they pick the new dependencies up"
"${CO[@]}" restart frontend manager landing >/dev/null 2>&1
log_ok "frontend, manager, landing restarted"
