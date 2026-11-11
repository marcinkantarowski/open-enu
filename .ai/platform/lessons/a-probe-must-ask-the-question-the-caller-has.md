---
tags: [deployment, preflight, dns, networking]
date: 2026-11-11
phase: 9
---
# A probe must ask the question the caller has

## What happened
The first project to run `make preflight` against real servers was stopped three times, on
two servers that were fine - and `deploy-key` a fourth.

Staging, on `stg.example.com`: "no nameservers found - the domain is not delegated yet".
The zone was delegated and every record was in place. Preflight had asked for the NS
records of `stg.example.com` itself, and a name inside a zone has none.

Production: "port 80 is closed - check ufw and the cloud security group". ufw allowed 80 and
443 and dropped everything else. Nothing answered on 80 because the stack was not installed
yet - which is true of every server preflight is meant to run against before its first
deploy.

Then, given the server by host name: five A records "wrong", each pointing at the right
IPv4 address. `getent hosts` had resolved the dual-stack VPS to its IPv6 address, and the
records were compared against that.

`make deploy-key` then failed with "github.com host key does not match", got: empty. The
server's OpenSSH 10 prints ssh-keyscan's `# github.com:22 SSH-2.0-...` banner on stdout;
`head -1` took the banner for the key.

## Why it happened
Both probes answered the question the tool asks instead of the one the caller has.

- `dig NS <name>` answers "is this name the apex of a zone?". The caller wanted "which zone
  holds this name, and who is authoritative for it?".
- A TCP connect that fails answers "is something listening?". The caller wanted "will Let's
  Encrypt's packet reach this server?". A reset (`connection refused`) says yes - the packet
  arrived and the kernel answered. Only silence says a firewall dropped it.
- `getent hosts` answers "what address does this machine prefer?". The caller wanted "what
  IPv4 address must the A records hold?".

Neither was ever run against a server: the scripts were written before there was one, and
every developer machine they ran on had the stack up already.

## The rule
**Name the question first, then the probe that answers it.** In `scripts/lib/net.sh`:

- `dns_zone_of` walks up a label at a time to the first name with nameservers. The NS, the
  CAA set (read the way a CA reads it, RFC 8659) and the Cloudflare zone all come from there.
- `tcp_probe` returns `open`, `refused` or `filtered`. Preflight passes `refused` and fails
  only `filtered`.
- `ipv4_of` asks for IPv4 only, because A records are what it is compared with.
- `host_key_line` takes the first line that is a key, not the first line.

`make net-check` proves all of it offline, with a stand-in for dig and with ports on the local
machine; `make selftest` proves it fails when any is broken.

## How it was caught
By the first real staging and production server. Reproduced by hand with
`timeout 5 bash -c 'exec 3<>/dev/tcp/<ip>/<port>'` on 80 (refused at once) against 81
(silent for five seconds).

## Where else this applies
- Anything that reports a state before the thing it checks exists - a health check on a
  first deploy, a backup check before the first backup. Decide what "not yet" looks like and
  pass it.
- Any lookup on a name: the name may sit inside something larger that holds the answer.
