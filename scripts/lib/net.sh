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
# wrong. `ahostsv4` asks for IPv4 only; dig is the fallback where getent is
# missing (macOS).
ipv4_of() {
  local host="$1" ip
  if command -v getent >/dev/null 2>&1; then
    ip="$(getent ahostsv4 "$host" 2>/dev/null | awk '{print $1; exit}')"
  fi
  if [ -z "${ip:-}" ]; then
    ip="$(${DIG:-dig} +short A "$host" 2>/dev/null | grep -E '^[0-9]+(\.[0-9]+){3}$' | head -1)"
  fi
  [ -n "${ip:-}" ] && echo "$ip"
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
  err="$(timeout "$seconds" bash -c "exec 3<>/dev/tcp/$host/$port" 2>&1)"
  rc=$?
  if [ "$rc" -eq 0 ]; then
    echo open
  elif [ "$rc" -ne 124 ] && grep -qi 'refused' <<<"$err"; then
    echo refused
  else
    echo filtered
  fi
}
