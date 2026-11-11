#!/usr/bin/env bash
# =============================================================================
#  Network probes - the answers preflight builds on, proved without a network.
# =============================================================================
#  scripts/lib/net.sh decides three things a fresh server depends on: which zone a
#  staging name lives in, which address the A records must match, and whether a closed port is a firewall or simply an
#  empty server. Both were wrong once, and both are invisible until the first
#  real VPS - so they are proved here, on every `make check`, with a stand-in
#  for dig and with ports on this machine.
#
#  See .ai/platform/lessons/a-probe-must-ask-the-question-the-caller-has.md.
# =============================================================================
set -uo pipefail
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(cd "$HERE/../.." && pwd)"
. "$HERE/../lib/log.sh"
. "$ROOT/scripts/lib/net.sh"

log_step "Network probes - zone lookup and port states"

fails=0
expect() { # <label> <want> <got>
  if [ "$2" = "$3" ]; then
    log_ok "$1"
  else
    log_fail "$1" "expected '$2', got '${3:-nothing}'"
    fails=$((fails + 1))
  fi
}

# ── a stand-in for dig: only example.com and com have nameservers ───────────
FAKE="$(mktemp -d)"
trap 'rm -rf "$FAKE"' EXIT
cat > "$FAKE/dig" <<'DIG'
#!/usr/bin/env bash
# dig +short NS <name>
case "${3%.}" in
  example.com) echo "ns1.example.net." ;;
  com)         echo "a.gtld-servers.net." ;;
esac
DIG
chmod +x "$FAKE/dig"
export DIG="$FAKE/dig"

expect "an apex is its own zone"              example.com "$(dns_zone_of example.com)"
expect "a staging name lives in the apex zone" example.com "$(dns_zone_of stg.example.com)"
expect "a deeper name walks up to it"          example.com "$(dns_zone_of app.stg.example.com.)"
expect "an undelegated name has no zone"       ""          "$(dns_zone_of nowhere.invalid)"

# ── the server's address, compared with A records, must be IPv4 ──────────
# `localhost` resolves to ::1 first on most Linux hosts - the same preference
# that turned a dual-stack VPS into five "wrong" A records.
expect "a host name resolves to its IPv4 address" 127.0.0.1 "$(ipv4_of localhost)"

# ── ports on this machine ───────────────────────────────────────────────────
# A port nothing listens on: the kernel answers with a reset. Picked by asking
# for one and closing it, so nothing else on the host can be holding it.
free_port() {
  local p
  for p in $(seq 47000 47100); do
    timeout 1 bash -c "exec 3<>/dev/tcp/127.0.0.1/$p" 2>/dev/null || { echo "$p"; return; }
  done
}
port="$(free_port)"
[ -n "$port" ] || die "no free local port in 47000-47100" "the check cannot run, which is not the same as passing"
expect "a port nothing listens on is refused, not filtered" refused "$(tcp_probe 127.0.0.1 "$port" 2)"

# TEST-NET-1 (RFC 5737) is never routed: nothing answers, which is what a
# firewall that drops packets looks like from outside.
expect "an address that never answers is filtered" filtered "$(tcp_probe 192.0.2.1 80 1)"

[ "$fails" -eq 0 ] || exit 1
