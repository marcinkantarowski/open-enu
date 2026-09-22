#!/usr/bin/env bash
# =============================================================================
#  One Symfony line - every framework component on the framework's version.
# =============================================================================
#  The app is on one Symfony line (7.4 LTS, PLAN §3). Most components accept
#  `^7.4|^8.0`, so what keeps them on 7.4 is Flex's `extra.symfony.require` -
#  and only when the Flex plugin runs. Dependabot runs composer without
#  plugins: it once resolved thirteen components to 8.0 beside a 7.4 kernel,
#  and the first migration died on a missing lazy-ghost class.
#
#  So backend/composer.json carries a `conflict` entry for every component,
#  which composer honours with or without plugins. This check keeps that list
#  complete and the lock inside it:
#
#    - every symfony/* package released in lockstep with the framework is on
#      symfony/framework-bundle's major.minor
#    - every one of them has `conflict: ">=<next major>.0"` in composer.json
#
#  Moving to the next major is a deliberate change of all of it at once.
#  Static - reads composer.json and composer.lock, needs no running stack.
# =============================================================================
set -uo pipefail
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(cd "$HERE/../.." && pwd)"
. "$HERE/../lib/log.sh"

log_step "Symfony - one framework line"

JSON="$ROOT/backend/composer.json"
LOCK="$ROOT/backend/composer.lock"
[ -f "$JSON" ] && [ -f "$LOCK" ] \
  || die "backend/composer.json or composer.lock is missing" "the check cannot run, which is not the same as passing"

problems="$(python3 - "$JSON" "$LOCK" <<'PY'
import json, re, sys
manifest = json.load(open(sys.argv[1]))
lock = json.load(open(sys.argv[2]))
packages = {p["name"]: p["version"].lstrip("v") for p in lock["packages"] + lock.get("packages-dev", [])}

framework = packages.get("symfony/framework-bundle")
if not framework:
    print("symfony/framework-bundle is not in composer.lock")
    sys.exit()
major, minor = (int(x) for x in framework.split(".")[:2])
want_conflict = f">={major + 1}.0"
conflicts = manifest.get("conflict", {})

# Lockstep: a symfony/* package versioned like the framework. Contracts (3.x),
# polyfills (1.x), flex (2.x) and the separately versioned bundles are below 5.
bad = []
for name, version in sorted(packages.items()):
    m = re.match(r"(\d+)\.(\d+)", version)
    if not name.startswith("symfony/") or not m or int(m.group(1)) < 5:
        continue
    if (int(m.group(1)), int(m.group(2))) != (major, minor):
        bad.append(f"{name} is {version}, the framework is {major}.{minor} - composer.lock mixes two Symfony lines")
    if conflicts.get(name) != want_conflict:
        bad.append(f'{name} has no "conflict": {{"{name}": "{want_conflict}"}} in backend/composer.json')
print("\n".join(bad))
PY
)"

if [ -n "$problems" ]; then
  while IFS= read -r line; do log_fail "$line"; done <<<"$problems"
  echo
  log_fail "fix: add the missing conflicts, then composer update --lock (in the api container)" \
           "a new major is an upgrade of every component at once - change every conflict together"
  exit 1
fi
log_ok "every Symfony component is on the framework's line and fenced from the next major"
