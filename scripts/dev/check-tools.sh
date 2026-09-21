#!/usr/bin/env bash
# =============================================================================
#  Toolchain check - split by WHEN each tool is needed.
# =============================================================================
#  A missing `rclone` must not block day one, and a missing `docker` must not be
#  discovered halfway through provisioning a server. So: hard-fail on what
#  `builddev` needs, report-only on what `buildprod` will need later.
# =============================================================================
set -uo pipefail
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
. "$HERE/../lib/log.sh"; . "$HERE/../lib/require.sh"; . "$HERE/../lib/os.sh"

rc=0

log_step "Required for local development"
require_tool docker  "https://docs.docker.com/engine/install/"            || rc=1
if docker compose version >/dev/null 2>&1; then
  log_ok "docker compose"
else
  log_fail "docker compose (v2 plugin) is not available" \
    "install: apt-get install docker-compose-plugin" \
    "the legacy 'docker-compose' binary is not supported" || rc=1
fi
require_tool git      "apt-get install git"                               || rc=1
require_tool openssl  "apt-get install openssl"                           || rc=1
require_tool mkcert   "https://github.com/FiloSottile/mkcert#installation" \
  "generates the locally-trusted wildcard certificate for *.${DOMAIN:-open-enu.local}" || rc=1

log_step "Required to deploy (staging / production)"
want_tool dig    "apt-get install dnsutils"                   "make preflight cannot verify DNS without it"
want_tool rclone "https://rclone.org/install/"                "make backup cannot push off-box without it"
want_tool age    "apt-get install age"                        "make backup cannot encrypt dumps without it"
want_tool gh     "https://cli.github.com/"                    "make deploy-key falls back to manual paste without it"
want_tool rsync  "apt-get install rsync"                      "SYNC=rsync deploys are unavailable without it"

log_step "Host"
kind="$(os_kind)"
log_ok "platform: $kind"

if [ "$kind" = wsl2 ]; then
  log_info "WSL2 is a first-class dev host here, but two things live on the Windows side:"
  hosts="$(win_hosts_path)"
  if [ -n "$hosts" ] && [ -f "$hosts" ]; then
    log_ok "Windows hosts file reachable: $hosts"
  else
    log_warn "Windows hosts file not reachable - is /mnt/c mounted?"
    log_info "the browser resolves names via Windows, not via WSL's /etc/hosts"
  fi
  if command -v mkcert.exe >/dev/null 2>&1; then
    log_ok "mkcert.exe (Windows) - the CA can be trusted by the Windows browser"
  else
    log_warn "mkcert.exe not on PATH - the Windows browser will not trust the dev CA"
    log_info "install mkcert on Windows too: choco install mkcert  (or scoop install mkcert)"
  fi
fi

watches="$(inotify_watches)"
if [ "$watches" -lt 262144 ] 2>/dev/null; then
  log_warn "fs.inotify.max_user_watches = $watches (low)"
  log_info "Nuxt hot reload dies silently with three apps running. Fix:"
  log_info "  echo 'fs.inotify.max_user_watches=524288' | sudo tee /etc/sysctl.d/60-inotify.conf"
  log_info "  sudo sysctl -p /etc/sysctl.d/60-inotify.conf"
else
  log_ok "fs.inotify.max_user_watches = $watches"
fi

# Node/PHP on the host are a convenience (IDE, quick scripts); the containers
# carry the versions that matter. Report, never fail.
log_step "Host runtimes (optional - containers carry the real versions)"
if command -v node >/dev/null 2>&1; then
  nv="$(node -v)"; major="${nv#v}"; major="${major%%.*}"
  if [ "$major" -ge 22 ] 2>/dev/null; then log_ok "node $nv"
  else log_warn "node $nv on host; containers use 22 LTS - IDE type-checking may differ"; fi
else
  log_skip "node not installed on host"
fi
if command -v php >/dev/null 2>&1; then log_ok "php $(php -r 'echo PHP_VERSION;')"; else log_skip "php not installed on host"; fi
if command -v composer >/dev/null 2>&1; then log_ok "composer $(composer --version --no-interaction 2>/dev/null | awk '{print $3}')"; else log_skip "composer not installed on host"; fi

echo
if [ $rc -eq 0 ]; then log_ok "toolchain ready for: make env, make builddev"
else log_fail "install the tools marked ✗ above, then re-run: make check-tools"; fi
exit $rc
