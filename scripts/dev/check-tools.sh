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
. "$HERE/../lib/log.sh"; . "$HERE/../lib/require.sh"; . "$HERE/../lib/os.sh"; . "$HERE/../lib/tools.sh"

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
# Everything else comes from lib/tools.sh, the table install-dev-tools.sh
# installs from - one list, so the two cannot disagree.
tools_of() {
  local row
  while IFS= read -r row; do
    if [ "$(tool_field "$row" 2)" = "$1" ]; then echo "$row"; fi
  done < <(host_tools)
}
why_of() { local w; w="$(tool_field "$1" 6)"; echo "${w//\$\{DOMAIN\}/${DOMAIN:-open-enu.local}}"; }

while IFS= read -r row; do
  require_tool "$(tool_field "$row" 1)" "$(tool_field "$row" 5)" "$(why_of "$row")" || rc=1
done < <(tools_of dev)
while IFS= read -r row; do
  want_tool "$(tool_field "$row" 1)" "$(tool_field "$row" 5)" "$(why_of "$row")"
done < <(tools_of dev-extra)

log_step "Required to deploy (staging / production)"
while IFS= read -r row; do
  want_tool "$(tool_field "$row" 1)" "$(tool_field "$row" 5)" "$(why_of "$row")"
done < <(tools_of deploy)

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
  # What matters is whether Windows trusts the CA, not whether mkcert.exe is
  # installed: certs.sh copies the Linux CA into the Windows user store with
  # certutil.exe, which ships with Windows. This used to demand mkcert.exe and
  # warn about a browser that was in fact already trusting the certificate.
  ca="$(mkcert -CAROOT 2>/dev/null)/rootCA.pem"
  if ! command -v certutil.exe >/dev/null 2>&1; then
    log_warn "certutil.exe not reachable from WSL - the dev CA cannot be handed to Windows"
    log_info "is Windows interop enabled? (/etc/wsl.conf [interop] enabled=true)"
  elif [ ! -f "$ca" ]; then
    log_skip "dev CA not created yet - make certs creates it and hands it to Windows"
    log_info "Windows will ask once to confirm adding a root certificate"
  elif certutil.exe -user -verifystore Root \
         "$(openssl x509 -in "$ca" -noout -fingerprint -sha1 | cut -d= -f2 | tr -d ':')" >/dev/null 2>&1; then
    log_ok "Windows trusts the dev CA - the browser will accept the certificate"
  else
    log_warn "Windows does not trust the dev CA yet - the browser will show a warning"
    log_info "run: make certs   (Windows asks once to confirm adding a root certificate)"
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
# The containers' Node major is read from the image, not written here: a copy
# written here said "22 LTS" long after the image moved to 26.
want="$(sed -n 's/^FROM node:\([0-9][0-9]*\).*/\1/p' "$HERE/../../docker/node/Dockerfile" | head -1)"
if command -v node >/dev/null 2>&1; then
  nv="$(node -v)"; major="${nv#v}"; major="${major%%.*}"
  if [ -z "$want" ]; then log_ok "node $nv (could not read the containers' version from docker/node/Dockerfile)"
  elif [ "$major" = "$want" ]; then log_ok "node $nv"
  else log_warn "node $nv on host; containers use $want - IDE type-checking may differ"; fi
else
  log_skip "node not installed on host"
fi
if command -v php >/dev/null 2>&1; then log_ok "php $(php -r 'echo PHP_VERSION;')"; else log_skip "php not installed on host"; fi
if command -v composer >/dev/null 2>&1; then log_ok "composer $(composer --version --no-interaction 2>/dev/null | awk '{print $3}')"; else log_skip "composer not installed on host"; fi

echo
if [ $rc -eq 0 ]; then log_ok "toolchain ready for: make env, make builddev"
else log_fail "install the tools marked ✗ above - or let the installer do it: make installdevtools"; fi
exit $rc
