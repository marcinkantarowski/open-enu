#!/usr/bin/env bash
# Block until the stack is actually usable. Compose's `up -d` returns when
# containers are *created*, not when they serve - every step after this one
# would otherwise race it.
set -uo pipefail
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(cd "$HERE/../.." && pwd)"
. "$HERE/../lib/log.sh"
. "$ROOT/scripts/lib/compose.sh"; compose_init "$ROOT"
CO=("${COMPOSE[@]}")

# wait_healthy <service> <seconds>
wait_healthy() {
  local svc="$1" limit="${2:-90}" i=0 cid state
  cid="$("${CO[@]}" ps -q "$svc" 2>/dev/null)"
  [ -n "$cid" ] || { log_fail "$svc has no container" "run: make up"; return 1; }
  while [ "$i" -lt "$limit" ]; do
    state="$(docker inspect -f '{{if .State.Health}}{{.State.Health.Status}}{{else}}{{.State.Status}}{{end}}' "$cid" 2>/dev/null)"
    case "$state" in
      healthy|running) log_ok "$svc ($state)"; return 0 ;;
      # `unhealthy` is NOT terminal: a container restarted a moment ago reports
      # it until its first probe succeeds. Only the timeout below is terminal.
      exited|dead)     log_fail "$svc exited" "inspect: $(printf '%s ' "${CO[@]}") logs $svc"; return 1 ;;
    esac
    sleep 1; i=$((i + 1))
  done
  log_fail "$svc did not become healthy within ${limit}s (last state: ${state:-unknown})" \
    "inspect: $(printf '%s ' "${CO[@]}") logs $svc"
  return 1
}

rc=0
log_step "Waiting for infrastructure"
wait_healthy postgres 90 || rc=1
wait_healthy redis    60 || rc=1
wait_healthy mercure  60 || rc=1

log_step "Waiting for applications"
# The API is fast; the three Nuxt dev servers compile on boot and are the
# slowest thing in the build. Waiting on their healthchecks is what keeps the
# smoke test from racing them.
wait_healthy api      120 || rc=1
wait_healthy frontend 180 || rc=1
wait_healthy manager  180 || rc=1
wait_healthy landing  180 || rc=1
exit $rc
