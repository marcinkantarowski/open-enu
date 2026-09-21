#!/usr/bin/env bash
# =============================================================================
#  Make the running stack match the checkout. Runs ON the server, in the stack's
#  own checkout, AFTER the caller has moved it to the commit to release.
# =============================================================================
#  One script for provisioning, deploying and promoting, so the three cannot
#  drift into three slightly different releases.
#
#  The caller moves the checkout, not this script: bash reads a script as it
#  runs, and a script that `git checkout`s a new version of itself executes a
#  mix of both.
#
#    production   build → back up → migrate → edge → up → warm up → health
#                 Nothing running is touched until the build and the migration
#                 have both succeeded.
#    staging      the dev stack: make up (edge + attach), deps, migrate, flags.
#                 Hot reload does the rest; this exists for dependencies,
#                 migrations and containers that changed.
#
#  Usage: release.sh [PREVIOUS_COMMIT]   - named in the rollback hint on failure
# =============================================================================
set -uo pipefail
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(cd "$HERE/../.." && pwd)"
. "$HERE/../lib/log.sh"; . "$HERE/../lib/compose.sh"
cd "$ROOT" || die "cannot enter $ROOT"

BEFORE="${1:-}"
[ -f .env ] || die "$ROOT/.env is missing" "provision this stack first: make buildstaging / make buildprod"
env_value() { sed -n "s/^$1=//p" .env | head -1; }
STAGE="$(env_value APP_STAGE)"
DOMAIN="$(env_value DOMAIN)"
STACK="$(env_value STACK)"
compose_init "$ROOT"
AT="$(git rev-parse --short HEAD 2>/dev/null || echo 'a working tree')"

rollback_hint() {
  [ -n "$BEFORE" ] || return 0
  log_info "roll back: cd $ROOT && git checkout --detach $BEFORE && bash scripts/remote/release.sh"
}
fail() { log_fail "$@" || true; rollback_hint; exit 1; }

log_step "Releasing $AT to $STACK ($STAGE)"

# Derived files are regenerated from .env, which is preserved. Skipping this is
# how a variable added upstream is missing on the server.
./scripts/lib/envgen.sh generate >/dev/null || fail "envgen failed"

case "$STAGE" in
  production)
    log_step "Building"
    "${COMPOSE[@]}" build --quiet || fail "build failed - the running stack is untouched"

    log_step "Backup, then migrate"
    bash scripts/remote/backup.sh pre-deploy >/dev/null 2>&1 \
      && log_ok "pre-deploy dump taken" || log_skip "no database to dump yet"
    "${COMPOSE[@]}" run --rm -T api php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration >/dev/null \
      || fail "migrations failed - nothing was restarted, the old containers are still serving" \
              "the pre-deploy dump is in $ROOT/backups"
    log_ok "migrated"

    bash scripts/dev/edge.sh up || fail "the edge is not running"
    log_step "Restarting (brief downtime)"
    "${COMPOSE[@]}" up -d --remove-orphans || fail "restart failed"
    bash scripts/dev/edge.sh attach || fail "could not attach the edge"
    "${COMPOSE[@]}" exec -T api sh -c 'php bin/console cache:warmup && php bin/console app:flags:sync' >/dev/null 2>&1 || true

    # Through the edge, with the certificate: the question is whether the
    # public URL works, not whether PHP starts. --resolve so a cloud's NAT
    # hairpin cannot make a healthy stack look down.
    log_step "Health - https://api.$DOMAIN/health/deep"
    for _ in $(seq 1 60); do
      if curl -fsS --max-time 5 --resolve "api.$DOMAIN:443:127.0.0.1" "https://api.$DOMAIN/health/deep" 2>/dev/null \
         | grep -q '"status":"ok"'; then
        log_ok "deep health check green"
        log_ok "$DOMAIN is running $AT"
        exit 0
      fi
      sleep 4
    done
    fail "the stack did not come back healthy within 240s" \
      "a first certificate can take a minute - inspect: docker logs enu-edge; ${COMPOSE[*]} logs --tail=80 api"
    ;;

  staging)
    make --no-print-directory up    || fail "the staging stack did not start"
    make --no-print-directory deps  || fail "dependencies did not install"
    make --no-print-directory wait  || fail "the staging stack did not become healthy"
    make --no-print-directory migrate >/dev/null || fail "migrations failed"
    make --no-print-directory flags >/dev/null 2>&1 || true
    log_ok "migrated, flags synced"

    # Inside the stack, not through the edge: staging answers only to the
    # allowlist, and this server's own address is not on it.
    if "${COMPOSE[@]}" exec -T api curl -fsS --max-time 10 http://localhost/health/deep 2>/dev/null | grep -q '"status":"ok"'; then
      log_ok "staging is running $AT - edit, and it reloads"
      exit 0
    fi
    fail "staging did not pass its deep health check" "inspect: make logs-api"
    ;;

  *) die "APP_STAGE is '$STAGE' in $ROOT/.env" "release.sh runs on a server: staging or production" ;;
esac
