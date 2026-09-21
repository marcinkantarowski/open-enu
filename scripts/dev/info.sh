#!/usr/bin/env bash
# Identity and host summary - the "where am I" target.
set -uo pipefail
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(cd "$HERE/../.." && pwd)"
. "$HERE/../lib/log.sh"; . "$HERE/../lib/os.sh"

m() { sed -n "s/.*\"$1\"[[:space:]]*:[[:space:]]*\"\([^\"]*\)\".*/\1/p" "$ROOT/.project.json" | head -1; }
SLUG="$(m slug)"; NAME="$(m name)"; DOM="$(m domain)"
# Read the boolean, never compare the slug to a literal - init rewrites literals.
INITIALIZED="$(sed -n 's/.*"initialized"[[:space:]]*:[[:space:]]*\(true\|false\).*/\1/p' "$ROOT/.project.json" | head -1)"

log_step "Project"
log_info "name    $NAME"
log_info "slug    $SLUG"
log_info "domain  $DOM"
[ "$INITIALIZED" = false ] && log_warn "not initialized yet - run: make init NAME=yourproject"

log_step "Kernel (never renamed by \`make init\`)"
log_info "package    open-enu/kernel"
log_info "namespace  OpenEnu\\Kernel\\"
log_info "ui layer   @open-enu/ui-kit"

log_step "Hosts this stack will serve"
for h in "$DOM" "www.$DOM" "app.$DOM" "manager.$DOM" "api.$DOM"; do
  log_info "https://$h"
done

log_step "Environment"
log_info "platform  $(os_kind)"
if [ -f "$ROOT/.env" ]; then
  log_info "APP_ENV   $(sed -n 's/^APP_ENV=//p' "$ROOT/.env" | head -1)"
  log_info "APP_STAGE $(sed -n 's/^APP_STAGE=//p' "$ROOT/.env" | head -1)"
  log_ok ".env present"
else
  log_warn ".env missing - run: make env"
fi
