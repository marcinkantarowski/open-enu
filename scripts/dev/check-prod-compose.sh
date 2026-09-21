#!/usr/bin/env bash
# =============================================================================
#  The production AND staging stacks' invariants, checked (.ai/platform/PLAN.md §8.2).
# =============================================================================
#  Every one of these is a sentence the plan writes about the prod compose file,
#  and a sentence nobody re-reads. They are also all one careless edit from being
#  false - and each would be false SILENTLY, in production, until the day it
#  mattered:
#
#    a bind-mounted source   makes opcache.validate_timestamps=0 serve stale code
#    an exposed DB port      puts Postgres on the public internet
#    an unpinned image       deploys a different system six months from now
#    a missing cert resolver makes a host answer on 443 with a wrong certificate
#
#  And for staging, which is the DEV stack on a public server:
#
#    a published port        is on the internet - Docker bypasses ufw
#    a router without the    exposes the profiler (environment variables) and
#    allowlist               Vite's dev server (source files) to everyone
#    a script that names     silently drops the staging override, and the next
#    compose.dev.yml itself  `up` on the server undoes both of the above
# =============================================================================
set -uo pipefail
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(cd "$HERE/../.." && pwd)"
. "$HERE/../lib/log.sh"

FILE="$ROOT/docker/compose.prod.yml"
[ -f "$FILE" ] || { log_skip "no production compose file yet"; exit 0; }

fails=0
bad() { log_fail "$@" || true; fails=$((fails + 1)); }

log_step "Production stack invariants"

# Rendered rather than grepped: compose resolves anchors, extends and variables,
# and a rule applied to the raw YAML checks the text instead of the stack.
CONFIG="$(docker compose --project-directory "$ROOT" --env-file "$ROOT/.env" -f "$FILE" config 2>/dev/null)" \
  || { log_fail "compose.prod.yml does not parse" "run: docker compose -f docker/compose.prod.yml config"; exit 1; }

# ── 1. no source bind-mounts ────────────────────────────────────────────────
# The scheduler script and the Traefik config are mounted on purpose; the
# application tree is not. A bind mount over /app means the image is no longer
# what runs, and opcache never notices the difference.
if grep -E '^\s+source: .*/(backend|frontend|manager|landing|ui-kit)' <<<"$CONFIG" | grep -q .; then
  bad "the production stack bind-mounts application source" \
      "images are immutable in production - that is what makes a rollback a tag change" \
      "$(grep -E '^\s+source: .*/(backend|frontend|manager|landing|ui-kit)' <<<"$CONFIG" | head -3)"
else
  log_ok "no application source is bind-mounted"
fi

# ── 2. no published database or cache port ──────────────────────────────────
published_ports() { # <service> -> prints its published ports, if any
  SERVICE="$1" python3 -c '
import os, re, sys
service = os.environ["SERVICE"]
config = sys.stdin.read()
block = re.search(rf"^  {service}:\n(.*?)(?=^  \S|\Z)", config, re.S | re.M)
if block:
    for line in re.findall(r"published:\s*\"?(\d+)", block.group(1)):
        print(line)
' <<<"$CONFIG"
}

for service in postgres redis; do
  ports="$(published_ports "$service")"
  if [ -n "$ports" ]; then
    bad "$service publishes port(s) $(tr '\n' ' ' <<<"$ports") in production" \
        "in dev it is exposed for a GUI client; here the only way in is \`make tunnel\`"
  else
    log_ok "$service publishes no port"
  fi
done

# ── 3. every image pinned ───────────────────────────────────────────────────
unpinned="$(grep -E '^\s+image: ' <<<"$CONFIG" | grep -vE ':[A-Za-z0-9][A-Za-z0-9._-]*$' || true)"
if [ -n "$unpinned" ]; then
  bad "an image is not pinned to a tag" \
      "\`latest\` means a pull in six months deploys a system nobody tested" \
      "$unpinned"
elif grep -E '^\s+image: .*:latest$' <<<"$CONFIG" | grep -qv "${STACK:-open-enu}-"; then
  bad "a third-party image is pinned to :latest" "pin a version"
else
  log_ok "every third-party image is pinned"
fi

# ── 4. everything public terminates TLS with a real resolver ────────────────
# Routers are DISCOVERED, not listed. They are named <slug>-<service>, and a
# fixed list of names meets none of them - the check would skip every router
# and report success. Finding no routers at all is a failure for the same
# reason: a check with nothing to look at has not passed.
mapfile -t routers < <(grep -oE 'traefik\.http\.routers\.[A-Za-z0-9_-]+\.rule' <<<"$CONFIG" \
                        | sed -E 's/^traefik\.http\.routers\.//; s/\.rule$//' | sort -u)
missing=0
if [ "${#routers[@]}" -eq 0 ]; then
  bad "no Traefik routers found in the production stack" "nothing is public, or the labels changed shape"
  missing=1
fi
for router in "${routers[@]}"; do
  grep -qF "traefik.http.routers.$router.tls.certresolver" <<<"$CONFIG" \
    || { bad "router $router has no certificate resolver" "it would answer on 443 with the wrong certificate"; missing=1; }
done
[ "$missing" -eq 0 ] && log_ok "every public router resolves a certificate (${#routers[@]})"

# ── 5. no dev-only services leaked in ───────────────────────────────────────
for service in mailpit webhook-echo e2e; do
  grep -qE "^  $service:" <<<"$CONFIG" \
    && bad "$service is in the production stack" "it is test or development infrastructure"
done
grep -q 'dashboard: true' "$ROOT/docker/edge/traefik.server.yml" 2>/dev/null \
  && bad "the Traefik dashboard is enabled on the server edge" "it is an unauthenticated map of every route"
[ "$fails" -eq 0 ] && log_ok "no development services"

# ═══ staging: the dev stack, on a public server ═════════════════════════════
log_step "Staging stack invariants"
SFILE="$ROOT/docker/compose.staging.yml"
[ -f "$SFILE" ] || { bad "docker/compose.staging.yml is missing" "staging would run the dev stack unguarded"; }
SCONFIG="$(STACK=check-staging docker compose --project-directory "$ROOT" --env-file "$ROOT/.env" \
            -f "$ROOT/docker/compose.dev.yml" -f "$SFILE" --profile e2e config 2>/dev/null)" \
  || { log_fail "compose.dev.yml + compose.staging.yml do not parse" || true; exit 1; }

# ── 6. no published port ────────────────────────────────────────────────────
published="$(grep -E '^\s+published: ' <<<"$SCONFIG" || true)"
if [ -n "$published" ]; then
  bad "the staging stack publishes a port" \
      "Docker writes its own iptables rules - ufw does not protect it; the edge is the only way in" \
      "$(head -3 <<<"$published")"
else
  log_ok "staging publishes no port"
fi

# ── 7. every router behind the allowlist ────────────────────────────────────
mapfile -t srouters < <(grep -oE 'traefik\.http\.routers\.[A-Za-z0-9_-]+\.rule' <<<"$SCONFIG" \
                         | sed -E 's/^traefik\.http\.routers\.//; s/\.rule$//' | sort -u)
unguarded=0
[ "${#srouters[@]}" -gt 0 ] || { bad "no routers found in the staging stack" "the labels changed shape"; unguarded=1; }
for router in "${srouters[@]}"; do
  grep -E "traefik\.http\.routers\.$router\.middlewares: .*check-staging-allow@file" <<<"$SCONFIG" >/dev/null \
    || { bad "staging router $router is not behind the allowlist" \
             "add it to docker/compose.staging.yml: \${STACK}-allow@file,noindex@file"; unguarded=1; }
done
[ "$unguarded" -eq 0 ] && log_ok "every staging router is behind the allowlist (${#srouters[@]})"

# ── 8. nothing drops the override ───────────────────────────────────────────
# Code only: a comment explaining the rule names the pattern too.
hardcoded="$(grep -rnE -- "-f [\"']?[^ ]*compose\.dev\.yml" "$ROOT/scripts" "$ROOT/Makefile" 2>/dev/null \
             | grep -vE '^[^:]+:[0-9]+:[[:space:]]*#' | grep -vE '(check-prod-compose|selftest)\.sh:' | cut -d: -f1,2 || true)"
if [ -n "$hardcoded" ]; then
  bad "a script names compose.dev.yml itself instead of using scripts/lib/compose.sh" \
      "on a staging server that drops compose.staging.yml - the next \`up\` publishes Postgres" \
      "$(sed "s|$ROOT/||" <<<"$hardcoded")"
else
  log_ok "every script reads COMPOSE_FILE"
fi

echo
[ "$fails" -eq 0 ] && { log_ok "the production and staging stacks hold every invariant"; exit 0; }
log_fail "$fails problem(s) above"
exit 1
