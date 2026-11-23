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
#
#  And for staging from images (STAGING_MODE=images, production's stack plus
#  compose.staging-images.yml): the same allowlist and port rules, and the
#  production rule against bind-mounted source.
# =============================================================================
set -uo pipefail
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(cd "$HERE/../.." && pwd)"
. "$HERE/../lib/log.sh"

FILE="$ROOT/docker/compose.prod.yml"
[ -f "$FILE" ] || { log_skip "no production compose file yet"; exit 0; }

fails=0
bad() { log_fail "$@" || true; fails=$((fails + 1)); }

# with_project <file>... → `-f` for each, followed by the project's file of the
# same name in docker/project/ (scripts/lib/compose.sh). Checked as it runs: a
# project's router is held to the same rules as the platform's.
with_project() {
  local f
  for f in "$@"; do
    printf -- '-f\n%s\n' "$f"
    [ -f "$ROOT/docker/project/${f##*/}" ] && printf -- '-f\n%s\n' "$ROOT/docker/project/${f##*/}"
  done
  return 0
}
mapfile -t PROD_FILES < <(with_project "$FILE")

log_step "Production stack invariants"

# Rendered rather than grepped: compose resolves anchors, extends and variables,
# and a rule applied to the raw YAML checks the text instead of the stack.
CONFIG="$(docker compose --project-directory "$ROOT" --env-file "$ROOT/.env" "${PROD_FILES[@]}" config 2>/dev/null)" \
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

# ── 1b. the Symfony cache is not kept across releases ───────────────────────
# Production never checks that a compiled container is current. On a volume,
# /app/var (or its cache) outlives the image that wrote it, and the next
# release boots on the previous one's container - opcache.validate_timestamps=0
# makes sure nothing notices. Each container warms its own (entrypoint.sh).
if grep -qE '^\s+target: /app/var(/cache)?$' <<<"$CONFIG"; then
  bad "the production stack keeps /app/var on a volume" \
      "the cache would survive into the next release - mount only /app/var/storage"
else
  log_ok "the Symfony cache lives and dies with its image"
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

# ── 3b. the services that keep data run the same image in dev and prod ──────
# A staging switches between the dev stack and production's images on the same
# volumes (STAGING_MODE). A newer Redis or Postgres writes a format an older
# one refuses to read: dev on redis:8 (8.10) and prod on 8.0 left a staging
# switched to images with a Redis that crash-looped on "Can't handle RDB format".
drift=0
for service in postgres redis mercure; do
  dev_image="$(sed -n "/^  $service:/,/^  [a-z]/s/^    image: //p" "$ROOT/docker/compose.dev.yml" | head -1)"
  prod_image="$(sed -n "/^  $service:/,/^  [a-z]/s/^    image: //p" "$FILE" | head -1)"
  [ "$dev_image" = "$prod_image" ] \
    || bad "$service runs ${dev_image:-nothing} in dev but ${prod_image:-nothing} in production" \
           "pin the same tag in docker/compose.dev.yml and docker/compose.prod.yml - a staging switched between them shares the volume" \
    || drift=1
done
[ "$drift" -eq 0 ] && log_ok "postgres, redis and mercure run the same image in dev and production"

# ── 4. everything public terminates TLS with a real resolver ────────────────
# Routers are DISCOVERED, not listed. They are named <slug>-<service>, and a
# fixed list of names meets none of them - the check would skip every router
# and report success. Finding no routers at all is a failure for the same
# reason: a check with nothing to look at has not passed.
#
# Each names its entrypoint: a router that names none is attached to every
# entrypoint, and since the edge's redirect to HTTPS sits below every router,
# it would answer over plain HTTP. `websecure` needs a certificate; `web` is
# plain HTTP on purpose - for a client that cannot follow a redirect - and is
# named below so that nobody adds one without seeing it.
mapfile -t routers < <(grep -oE 'traefik\.http\.routers\.[A-Za-z0-9_-]+\.rule' <<<"$CONFIG" \
                        | sed -E 's/^traefik\.http\.routers\.//; s/\.rule$//' | sort -u)
missing=0
plain=()
secure=()
if [ "${#routers[@]}" -eq 0 ]; then
  bad "no Traefik routers found in the production stack" "nothing is public, or the labels changed shape"
  missing=1
fi
for router in "${routers[@]}"; do
  entrypoints="$(grep -oE "traefik\.http\.routers\.$router\.entrypoints: .*" <<<"$CONFIG" | sed -E 's/^[^:]+: //' || true)"
  case "$entrypoints" in
    websecure)
      secure+=("$router")
      grep -qF "traefik.http.routers.$router.tls.certresolver" <<<"$CONFIG" \
        || { bad "router $router has no certificate resolver" "it would answer on 443 with the wrong certificate"; missing=1; } ;;
    web)
      if grep -qE "traefik\.http\.routers\.$router\.tls" <<<"$CONFIG"; then
        bad "router $router is on the plain-HTTP entrypoint with TLS settings" "a router on web serves HTTP; its HTTPS twin is a router of its own"
        missing=1
      fi
      plain+=("$router") ;;
    *)
      bad "router $router does not name exactly one entrypoint (${entrypoints:-none})" \
          "add traefik.http.routers.$router.entrypoints=websecure - with none it also answers over plain HTTP"
      missing=1 ;;
  esac
done
[ "$missing" -eq 0 ] && log_ok "every public router resolves a certificate (${#routers[@]})"
[ "${#plain[@]}" -eq 0 ] || log_info "plain HTTP on purpose: ${plain[*]}"

# ── 4b. and is behind the rate limit ────────────────────────────────────────
# Named by each router, not by the entrypoint: the edge is shared with staging,
# whose dev server sends a first page load as several hundred requests from
# one VPN address - an entrypoint limit answered part of them 429.
unlimited=0
for router in "${secure[@]}"; do
  grep -qE "traefik\.http\.routers\.$router\.middlewares: .*rate-limit@file" <<<"$CONFIG" \
    || { bad "router $router is not behind the rate limit" \
             "add traefik.http.routers.$router.middlewares=rate-limit@file - the edge no longer applies it to every request"; unlimited=1; }
done
[ "$unlimited" -eq 0 ] && log_ok "every public router is behind the rate limit (${#secure[@]})"

# ── 5. no dev-only services leaked in ───────────────────────────────────────
for service in mailpit webhook-echo e2e; do
  grep -qE "^  $service:" <<<"$CONFIG" \
    && bad "$service is in the production stack" "it is test or development infrastructure"
done
grep -q 'dashboard: true' "$ROOT/docker/edge/traefik.server.yml" 2>/dev/null \
  && bad "the Traefik dashboard is enabled on the server edge" "it is an unauthenticated map of every route"
[ "$fails" -eq 0 ] && log_ok "no development services"

# ── 5b. the edge: Traefik faces the internet, so it holds no Docker socket ──
EDGE_CONFIG="$(EDGE_HOME=/nonexistent docker compose -f "$ROOT/docker/edge/compose.yml" config 2>/dev/null)" \
  || { bad "docker/edge/compose.yml does not parse" "run: EDGE_HOME=/tmp docker compose -f docker/edge/compose.yml config"; EDGE_CONFIG=""; }
edge_socket="$(python3 -c '
import re, sys
c = sys.stdin.read()
block = re.search(r"^  traefik:\n(.*?)(?=^  \S|^\S|\Z)", c, re.S | re.M)
print("missing" if not block else "socket" if "docker.sock" in block.group(1) else "clean")
' <<<"$EDGE_CONFIG")"
case "$edge_socket" in
  clean)  log_ok "Traefik reaches Docker through the socket proxy, not the socket" ;;
  socket) bad "the edge's Traefik mounts the Docker socket" \
              "the socket is root on the host, read-only or not; mount it into socket-proxy only" ;;
  *)      bad "no traefik service in docker/edge/compose.yml" "the edge changed shape" ;;
esac

# ═══ staging, in both of its modes ══════════════════════════════════════════
# staging_invariants <label> <compose file>... → 6, 7 and 7b against that stack
staging_invariants() {
  local label="$1"; shift
  local config published router unguarded=0 noresolver=0 override="${*: -1}"
  local -a files srouters
  override="${override##*/}"          # the staging override - where a router's fix goes
  mapfile -t files < <(with_project "$@")
  config="$(STACK=check-staging docker compose --project-directory "$ROOT" --env-file "$ROOT/.env" \
              "${files[@]}" --profile e2e config 2>/dev/null)" \
    || { bad "$label: $(printf '%s + ' "${@##*/}" | sed 's/ + $//') do not parse"; return; }

  # ── 6. no published port ──────────────────────────────────────────────────
  published="$(grep -E '^\s+published: ' <<<"$config" || true)"
  if [ -n "$published" ]; then
    bad "$label publishes a port" \
        "Docker writes its own iptables rules - ufw does not protect it; the edge is the only way in" \
        "$(head -3 <<<"$published")"
  else
    log_ok "$label publishes no port"
  fi

  # ── 7. every router behind the allowlist ──────────────────────────────────
  mapfile -t srouters < <(grep -oE 'traefik\.http\.routers\.[A-Za-z0-9_-]+\.rule' <<<"$config" \
                           | sed -E 's/^traefik\.http\.routers\.//; s/\.rule$//' | sort -u)
  [ "${#srouters[@]}" -gt 0 ] || { bad "no routers found in $label" "the labels changed shape"; unguarded=1; }
  for router in "${srouters[@]}"; do
    grep -E "traefik\.http\.routers\.$router\.middlewares: .*check-staging-allow@file" <<<"$config" >/dev/null \
      || { bad "$label router $router is not behind the allowlist" \
               "add it to docker/$override (a project's router: docker/project/$override): \${STACK}-allow@file,noindex@file"; unguarded=1; }
  done
  [ "$unguarded" -eq 0 ] && log_ok "every $label router is behind the allowlist (${#srouters[@]})"

  # ── 7b. and gets a public certificate ─────────────────────────────────────
  # The dev stack gives every router `tls=true` for its local certificates, and a
  # router's own TLS section replaces the entrypoint's default - certResolver
  # included. Without its own resolver label a staging router serves Traefik's
  # self-signed certificate, and Let's Encrypt is never asked.
  for router in "${srouters[@]}"; do
    grep -qF "traefik.http.routers.$router.tls.certresolver: letsencrypt" <<<"$config" \
      || { bad "$label router $router has no certificate resolver" \
               "add \${STACK}-<service>.tls.certresolver=letsencrypt - the dev stack's tls=true drops the edge's default"; noresolver=1; }
  done
  [ "$noresolver" -eq 0 ] && log_ok "every $label router resolves a public certificate (${#srouters[@]})"

  STAGING_CONFIG="$config"
}

log_step "Staging stack invariants (STAGING_MODE=dev: the dev stack, on a public server)"
SFILE="$ROOT/docker/compose.staging.yml"
[ -f "$SFILE" ] || { bad "docker/compose.staging.yml is missing" "staging would run the dev stack unguarded"; }
staging_invariants "staging" "$ROOT/docker/compose.dev.yml" "$SFILE"

log_step "Staging stack invariants (STAGING_MODE=images: production's stack, private)"
IFILE="$ROOT/docker/compose.staging-images.yml"
if [ -f "$IFILE" ]; then
  staging_invariants "image staging" "$FILE" "$IFILE"
  # The production rule, held here too: a project file could still mount source.
  if grep -E '^\s+source: .*/(backend|frontend|manager|landing|ui-kit)' <<<"$STAGING_CONFIG" | grep -q .; then
    bad "image staging bind-mounts application source" \
        "it rehearses production's images - a mount means it runs something production does not"
  else
    log_ok "image staging bind-mounts no application source"
  fi
  grep -qE '^  mailpit:' <<<"$STAGING_CONFIG" \
    && log_ok "image staging catches mail in Mailpit" \
    || bad "image staging has no Mailpit" "a rehearsal must not send mail to real people - keep the mailpit service in compose.staging-images.yml"
else
  bad "docker/compose.staging-images.yml is missing" "STAGING_MODE=images would run production's stack unguarded on staging"
fi

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

# ── workers and the code they run ───────────────────────────────────────────
# A worker runs the classes it started with. In development that means an
# edited handler keeps its old behaviour until something restarts the process,
# so every dev worker goes through worker-dev.sh, which does. In production the
# image cannot change underneath a worker, and nothing there may walk the
# source tree looking for edits.
log_step "Worker invariants"

DEV_FILE="$ROOT/docker/compose.dev.yml"
bare="$(grep -nE '^\s+command:.*messenger:consume' "$DEV_FILE" 2>/dev/null || true)"
if [ -n "$bare" ]; then
  bad "a development worker runs messenger:consume directly" \
      "it will keep running old code after an edit - use: command: sh /worker-dev.sh <transport>" \
      "$bare"
else
  log_ok "every development worker restarts when the code changes"
fi

if grep -q 'worker-dev.sh' "$FILE"; then
  bad "the production stack uses worker-dev.sh" \
      "that script polls the source tree and is for development only"
else
  log_ok "no production worker watches the source tree"
fi

echo
[ "$fails" -eq 0 ] && { log_ok "the production and staging stacks hold every invariant"; exit 0; }
log_fail "$fails problem(s) above"
exit 1
