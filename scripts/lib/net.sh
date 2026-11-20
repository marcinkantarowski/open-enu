#!/usr/bin/env bash
# Network probes for the remote targets. Read-only, and each one answers the
# question the caller actually has rather than the one the tool asks.
#
# Tested offline by scripts/dev/check-net.sh, with a stand-in for dig.
# See .ai/platform/lessons/a-probe-must-ask-the-question-the-caller-has.md.

# dns_zone_of <name> -> the zone that holds <name>, or nothing
#
# `stg.example.com` is a NAME inside the zone `example.com`, not a zone of its
# own, so asking for its NS returns nothing - which reads exactly like a domain
# nobody has delegated. Walk up one label at a time until something has
# nameservers. Stops before a bare TLD: `com` has nameservers too, and it is
# never the answer.
dns_zone_of() {
  local name="${1%.}" ns
  while [[ "$name" == *.* ]]; do
    ns="$(${DIG:-dig} +short NS "$name" 2>/dev/null | head -1)"
    if [ -n "$ns" ]; then
      echo "$name"
      return 0
    fi
    name="${name#*.}"
  done
  return 1
}

# ipv4_of <host> -> its IPv4 address, or nothing
#
# DNS is checked against A records, so the address it is compared with must be
# IPv4 too. `getent hosts` returns whatever the resolver prefers, and on a
# dual-stack VPS that is the IPv6 address: every correct A record then reads as
# wrong. `ahostsv4` asks for IPv4 only.
#
# macOS has no getent. Its equivalent is dscacheutil, which asks the system
# resolver - /etc/hosts included - exactly as getent does. dig is NOT an
# equivalent: it asks a DNS server and never reads /etc/hosts, so it is only the
# last resort, for a host with neither.
ipv4_of() {
  local host="$1" ip=""
  if [[ "$host" =~ ^[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
    echo "$host"
    return 0
  fi
  if command -v getent >/dev/null 2>&1; then
    ip="$(getent ahostsv4 "$host" 2>/dev/null | awk '{print $1; exit}')"
  elif command -v dscacheutil >/dev/null 2>&1; then
    ip="$(dscacheutil -q host -a name "$host" 2>/dev/null | awk '/^ip_address:/ {print $2; exit}')"
  fi
  if [ -z "$ip" ]; then
    ip="$(${DIG:-dig} +short A "$host" 2>/dev/null | grep -E '^[0-9]+(\.[0-9]+){3}$' | head -1)"
  fi
  [ -n "$ip" ] && echo "$ip"
}

# with_timeout <seconds> <command...> -> the command's status, or 124 when it
# ran out of time
#
# GNU `timeout` is not on macOS, and preflight runs from a developer's laptop.
# Called bare there, it is "command not found" - which tcp_probe read as
# `filtered`, so a port nothing listened on looked like a firewall. perl ships
# with macOS and with every Debian (perl-base is essential), and alarm + exec is
# all this needs: the alarm survives exec, so the command itself is killed.
# Nothing under scripts/dev or scripts/lib calls `timeout` bare; `make
# shell-check` fails on it.
with_timeout() {
  local seconds="$1" bin rc
  shift
  bin="$(command -v timeout || command -v gtimeout || true)"
  if [ -n "$bin" ]; then
    "$bin" "$seconds" "$@"
    return
  fi
  # The command keeps the caller's stderr (fd 3); only bash's own "Alarm clock"
  # report about the killed child goes to /dev/null - tcp_probe reads the
  # command's stderr, and the shell's noise would only clutter the caller's.
  { perl -e 'alarm shift; exec @ARGV or exit 127' "$seconds" "$@" 2>&3 3>&-; } 3>&2 2>/dev/null
  rc=$?
  [ "$rc" -eq 142 ] && return 124 # 128 + SIGALRM, reported the way GNU timeout does
  return "$rc"
}

# tcp_probe <host> <port> [seconds] -> open | refused | filtered
#
# A port nothing listens on yet and a port a firewall drops are different
# answers to "will Let's Encrypt reach this server?". `refused` means the
# packet arrived and the kernel answered: the firewall lets it through, and the
# stack has not started. `filtered` means nothing answered at all, which is a
# firewall or a cloud security group. A fresh server is always `refused`, and
# refusing to provision it for that is refusing to provision anything.
tcp_probe() {
  local host="$1" port="$2" seconds="${3:-5}" err rc
  err="$(with_timeout "$seconds" bash -c "exec 3<>/dev/tcp/$host/$port" 2>&1)"
  rc=$?
  if [ "$rc" -eq 0 ]; then
    echo open
  elif [ "$rc" -ne 124 ] && grep -qi 'refused' <<<"$err"; then
    echo refused
  else
    echo filtered
  fi
}

# host_key_line < ssh-keyscan output -> the first key line, or nothing
#
# ssh-keyscan prints a `# host:22 SSH-2.0-...` banner before the keys, and
# OpenSSH 10 prints it on stdout where older versions used stderr - so
# `2>/dev/null | head -1` takes the banner for the key and pins nothing.
host_key_line() {
  grep -m1 -E '^[^ #]+ (ssh-|ecdsa-)'
}
