#!/usr/bin/env bash
# =============================================================================
#  JWT signing keypair.
# =============================================================================
#  Generated per environment and never committed. Regenerating invalidates every
#  issued token, so this is deliberately idempotent: an existing key is reused,
#  never replaced. That is the same property that makes `make buildprod` safe to
#  re-run against a live server.
# =============================================================================
set -uo pipefail
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(cd "$HERE/../.." && pwd)"
. "$HERE/../lib/log.sh"

. "$ROOT/scripts/lib/compose.sh"; compose_init "$ROOT"
CO=("${COMPOSE[@]}")
JWT_DIR="$ROOT/backend/config/jwt"

if [ -f "$JWT_DIR/private.pem" ] && [ -f "$JWT_DIR/public.pem" ]; then
  log_skip "JWT keypair already present - regenerating would invalidate every issued token"
  exit 0
fi

log_step "Generating the JWT keypair"
mkdir -p "$JWT_DIR"

if "${CO[@]}" exec -T -u www-data api php bin/console lexik:jwt:generate-keypair --skip-if-exists --no-interaction 2>&1 | tail -2; then
  chmod 600 "$JWT_DIR"/*.pem 2>/dev/null || true
  log_ok "wrote backend/config/jwt/{private,public}.pem (gitignored)"
else
  log_fail "could not generate the keypair" "is the stack up? run: make up"
  exit 1
fi
