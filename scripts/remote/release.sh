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
#    staging      STAGING_MODE=images: production's path from the build to
#                 the warm-up, without the backup (staging data is disposable)
#                 and with the health check inside the stack (the allowlist
#                 refuses this server's own address).
#                 STAGING_MODE=dev: make up (edge + attach), deps, migrate,
#                 flags. Hot reload does the rest; this exists for
#                 dependencies, migrations and containers that changed.
#
#  On staging, COMPOSE_FILE, APP_ENV and APP_DEBUG follow STAGING_MODE: they
#  are set from it here, so switching a staging is one line in its .env and a
#  `make deploystaging`.
#
#  Usage: release.sh [PREVIOUS_COMMIT]   - named in the rollback hint on failure
# =============================================================================
set -uo pipefail
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(cd "$HERE/../.." && pwd)"
. "$HERE/../lib/log.sh"; . "$HERE/../lib/compose.sh"; . "$HERE/../lib/os.sh"; . "$HERE/../lib/stage.sh"
cd "$ROOT" || die "cannot enter $ROOT"

BEFORE="${1:-}"
[ -f .env ] || die "$ROOT/.env is missing" "provision this stack first: make buildstaging / make buildprod"
env_value() { sed -n "s/^$1=//p" .env | head -1; }
STAGE="$(env_value APP_STAGE)"
DOMAIN="$(env_value DOMAIN)"
STACK="$(env_value STACK)"

# set_env KEY VALUE → in .env, only when it differs; says so when it changes
set_env() {
  [ "$(env_value "$1")" = "$2" ] && return 0
  if grep -q "^$1=" .env; then sed_inplace "s|^$1=.*|$1=$2|" .env; else echo "$1=$2" >> .env; fi
  log_info "$1=$2 (follows STAGING_MODE)"
}

MODE=""
if [ "$STAGE" = staging ]; then
  MODE="$(env_value STAGING_MODE)"; MODE="${MODE:-dev}"
  staging_mode_valid "$MODE" || die "STAGING_MODE is '$MODE' in $ROOT/.env" "it is dev or images"
  set_env COMPOSE_FILE "$(stage_compose_files staging "$MODE")"
  if [ "$MODE" = images ]; then set_env APP_ENV prod; set_env APP_DEBUG 0
  else set_env APP_ENV dev; set_env APP_DEBUG 1; fi
fi
compose_init "$ROOT"
AT="$(git rev-parse --short HEAD 2>/dev/null || echo 'a working tree')"

rollback_hint() {
  [ -n "$BEFORE" ] || return 0
  log_info "roll back: cd $ROOT && git checkout --detach $BEFORE && bash scripts/remote/release.sh"
}
fail() { log_fail "$@" || true; rollback_hint; exit 1; }

# Every release rebuilds images: the previous ones lose their tag and the
# build cache only grows - one staging deploy added 4 GB to a 40 GB disk.
# Runs only after the release passed its health check, so a failed one keeps
# its cache for the retry. A rollback rebuilds from git (rollback_hint), never
# from an old image, so nothing removed here is needed to go back.
#
# The cache is pruned by age, not wiped: what this release just built was used
# a minute ago and stays, so the next release does not reinstall every
# dependency from scratch. By age and not by size: on Docker 29 the
# `--max-used-space` cap reclaimed nothing from a 3 GB cache.
prune_build_leftovers() {
  local before after
  before="$(df -Pm / | awk 'NR==2 {print $4}')"
  docker image prune -f >/dev/null 2>&1 || log_warn "could not prune unused images"
  docker builder prune -af --filter until=24h >/dev/null 2>&1 \
    || log_warn "could not prune the build cache"
  after="$(df -Pm / | awk 'NR==2 {print $4}')"
  log_ok "pruned old images and build cache unused for 24h - $(( after - before )) MB freed, $(( after / 1024 )) GB free"
}

log_step "Releasing $AT to $STACK ($STAGE)"

# Derived files are regenerated from .env, which is preserved. Skipping this is
# how a variable added upstream is missing on the server.
./scripts/lib/envgen.sh generate >/dev/null || fail "envgen failed"

# From images: production, and a staging in STAGING_MODE=images.
release_images() {
  log_step "Building"
  # --pull: fetch the newest php/node base images first. Without it a release
  # rebuilds on whatever the server pulled last time, and PHP security
  # releases never reach it (every other EnuSoft host sat on a July PHP on
  # the day of the 2026-10-21 critical release).
  "${COMPOSE[@]}" build --pull --quiet || fail "build failed - the running stack is untouched"

  if [ "$STAGE" = production ]; then
    log_step "Backup, then migrate"
    # A failed copy off the box does not stop a deploy: the dump a rollback
    # needs is the local one. It is said, though, not hidden behind "no database".
    if bash scripts/remote/backup.sh pre-deploy >/dev/null 2>&1; then
      log_ok "pre-deploy dump taken"
    elif ls "$ROOT"/backups/*-pre-deploy.dump* >/dev/null 2>&1; then
      log_warn "pre-deploy dump is on this disk only - the copy off the box failed (bash scripts/remote/backup.sh manual says why)"
    else
      log_skip "no database to dump yet"
    fi
  else
    log_step "Migrate (staging data is disposable - no dump)"
  fi
  "${COMPOSE[@]}" run --rm -T api php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration >/dev/null \
    || fail "migrations failed - nothing was restarted, the old containers are still serving" \
            "$([ "$STAGE" = production ] && echo "the pre-deploy dump is in $ROOT/backups" || echo "inspect: ${COMPOSE[*]} run --rm api php bin/console doctrine:migrations:status")"
  log_ok "migrated"

  bash scripts/dev/edge.sh up || fail "the edge is not running"
  log_step "Restarting (brief downtime)"
  "${COMPOSE[@]}" up -d --remove-orphans || fail "restart failed"
  bash scripts/dev/edge.sh attach || fail "could not attach the edge"
  # The cache is warmed by each container's entrypoint, from its own image.
  "${COMPOSE[@]}" exec -T api php bin/console app:flags:sync >/dev/null 2>&1 || true

  if [ "$STAGE" = production ]; then
    # Through the edge, with the certificate: the question is whether the
    # public URL works, not whether PHP starts. --resolve so a cloud's NAT
    # hairpin cannot make a healthy stack look down.
    log_step "Health - https://api.$DOMAIN/health/deep"
    probe() { curl -fsS --max-time 5 --resolve "api.$DOMAIN:443:127.0.0.1" "https://api.$DOMAIN/health/deep" 2>/dev/null; }
  else
    # Inside the stack, not through the edge: staging answers only to the
    # allowlist, and this server's own address is not on it.
    log_step "Health - /health/deep inside the stack"
    probe() { "${COMPOSE[@]}" exec -T api curl -fsS --max-time 5 http://127.0.0.1/health/deep 2>/dev/null; }
  fi
  for _ in $(seq 1 60); do
    if probe | grep -q '"status":"ok"'; then
      log_ok "deep health check green"
      log_ok "$DOMAIN is running $AT$([ "$STAGE" = staging ] && echo " - production's images, as staging")"
      prune_build_leftovers
      exit 0
    fi
    sleep 4
  done
  fail "the stack did not come back healthy within 240s" \
    "a first certificate can take a minute - inspect: docker logs enu-edge; ${COMPOSE[*]} logs --tail=80 api"
}

case "$STAGE/$MODE" in
  production/|staging/images)
    release_images
    ;;

  staging/dev)
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
      prune_build_leftovers
      exit 0
    fi
    fail "staging did not pass its deep health check" "inspect: make logs-api"
    ;;

  *) die "APP_STAGE is '$STAGE' in $ROOT/.env" "release.sh runs on a server: staging or production" ;;
esac
