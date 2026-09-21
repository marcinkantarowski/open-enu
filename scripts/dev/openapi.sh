#!/usr/bin/env bash
# =============================================================================
#  Export the OpenAPI spec, and fail if the committed copy is stale.
# =============================================================================
#  The spec is a committed artefact (ADR-0012) because three things read it: the
#  frontend's type generator, any MCP client, and an agent trying to learn the
#  API without booting it. A spec that is generated but not checked drifts within
#  a week, and then all three are working from fiction.
# =============================================================================
set -uo pipefail
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(cd "$HERE/../.." && pwd)"
. "$HERE/../lib/log.sh"

. "$ROOT/scripts/lib/compose.sh"; compose_init "$ROOT"
CO=("${COMPOSE[@]}")
TARGET="$ROOT/backend/openapi.json"
MODE="${1:-write}"

tmp="$(mktemp)"; trap 'rm -f "$tmp"' EXIT

if ! "${CO[@]}" exec -T -u www-data api php bin/console nelmio:apidoc:dump --format=json 2>/dev/null > "$tmp"; then
  log_fail "could not export the spec" "is the stack up? run: make up"
  exit 1
fi

# Pretty-printed and key-sorted, so a diff shows what actually changed rather
# than a reordering.
python3 - "$tmp" "$tmp.pretty" <<'PY'
import json, sys
with open(sys.argv[1]) as fh:
    spec = json.load(fh)
with open(sys.argv[2], 'w') as fh:
    json.dump(spec, fh, indent=2, sort_keys=True, ensure_ascii=False)
    fh.write("\n")
PY
mv "$tmp.pretty" "$tmp"

if [ "$MODE" = check ]; then
  if [ ! -f "$TARGET" ]; then
    log_fail "backend/openapi.json is missing" "run: make openapi"
    exit 1
  fi
  if ! diff -q "$TARGET" "$tmp" >/dev/null; then
    log_fail "backend/openapi.json is out of date" \
      "an endpoint or DTO changed without the spec being regenerated" \
      "run: make openapi && make types"
    diff -u "$TARGET" "$tmp" | head -30 >&2
    exit 1
  fi
  log_ok "openapi.json is current ($(python3 -c "import json;print(len(json.load(open('$TARGET'))['paths']))") paths)"
  exit 0
fi

cp "$tmp" "$TARGET"
log_ok "wrote backend/openapi.json ($(python3 -c "import json;print(len(json.load(open('$TARGET'))['paths']))") paths)"
