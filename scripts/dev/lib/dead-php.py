"""Classes under backend/src that nothing in the container or router reaches.

Advisory by design (.ai/platform/PLAN.md §12.3). A class can be reached in ways no static
view of the container shows - a Doctrine type registered by name, a migration
run by version, a fixture named in a string - so the exclusions below are not
exceptions to a rule, they are the rule: only what the container CAN see is
worth reporting at all.
"""

import json
import re
import sys
from pathlib import Path

SRC, CONTAINER, ROUTER = (Path(sys.argv[1]), Path(sys.argv[2]), Path(sys.argv[3]))

# Reached by the framework, by name, or by inheritance - never by a service id.
#
#   Entity/Dto/Command/Event/Message  data, constructed with `new`
#   Migrations/Fixtures               run by Doctrine, addressed by class name
#   Tests                             not a surface anything reuses
#   Acl                               a returned array, not a class
#   Contract                          interfaces; their implementations are services
EXEMPT_DIRS = {"Entity", "Dto", "Command", "Event", "Message", "Migrations", "Fixtures", "Tests", "Acl", "Contract", "Exception"}

reachable = set()

container = json.loads(CONTAINER.read_text())
for section in ("definitions", "aliases", "services"):
    entries = container.get(section, {})
    # `services` is a list when nothing is instantiated yet; the other two are
    # maps of id => definition.
    if not isinstance(entries, dict):
        continue

    for sid, entry in entries.items():
        reachable.add(sid)
        if isinstance(entry, dict):
            for key in ("class", "service"):
                if isinstance(entry.get(key), str):
                    reachable.add(entry[key])

if ROUTER.exists() and ROUTER.stat().st_size:
    for route in json.loads(ROUTER.read_text()).values():
        controller = route.get("defaults", {}).get("_controller")
        if isinstance(controller, str):
            reachable.add(controller.split("::")[0])

orphans = []
for path in sorted(SRC.rglob("*.php")):
    parts = path.relative_to(SRC).parts
    if EXEMPT_DIRS.intersection(parts):
        continue

    source = path.read_text()
    namespace = re.search(r"^namespace\s+([^;]+);", source, re.M)
    if not namespace:
        continue

    fqcn = f"{namespace.group(1)}\\{path.stem}"

    # An interface or a trait has no service id of its own; what implements it
    # does, and that is what would be reported.
    if re.search(r"^\s*(interface|trait|enum)\s", source, re.M):
        continue

    if fqcn not in reachable:
        orphans.append(fqcn)

if not orphans:
    print("  \033[32m✓\033[0m every class under backend/src is a service, a route target or exempt")
    sys.exit(0)

print(f"  \033[33m!\033[0m {len(orphans)} class(es) the container cannot reach - advisory, verify before deleting:")
for fqcn in orphans:
    print(f"      {fqcn}")
print("    Reached by a mechanism this cannot see? Say so in a docblock and move on.")
sys.exit(0)
