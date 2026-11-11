#!/usr/bin/env bash
# =============================================================================
#  Design tokens - screens use roles, never a palette (ADR-0024).
# =============================================================================
#  Two things a screen must not say:
#
#  - A raw Tailwind palette colour (`bg-blue-600`, `text-red-700`). It looks
#    right today and ignores the project's theme and every tenant's colours for
#    good - the one screen nobody can re-brand.
#  - A token the platform removed (`bg-brand-600`, `text-danger`). Tailwind does
#    not fail on a class it cannot resolve: it emits nothing, and after a
#    platform update the button simply has no background.
#
#  Static - reads the source, needs no running stack.
# =============================================================================
set -uo pipefail
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(cd "$HERE/../.." && pwd)"
. "$HERE/../lib/log.sh"

log_step "Design tokens - roles, not palette"

UTIL='(bg|text|border|ring|fill|stroke|from|to|via|outline|divide|decoration|placeholder|shadow|caret)'
PALETTE="-(slate|gray|zinc|neutral|stone|red|orange|amber|yellow|lime|green|emerald|teal|cyan|sky|blue|indigo|violet|purple|fuchsia|pink|rose)-[0-9]{2,3}\\b"
REMOVED="-(brand-[0-9]{2,3}|surface-muted|surface-sunken|danger|danger-soft|warning|warning-soft|success|success-soft)\\b"

dirs=()
for d in ui-kit/app frontend/app manager/app landing/app theme; do
  [ -d "$ROOT/$d" ] && dirs+=("$ROOT/$d")
done
[ "${#dirs[@]}" -gt 0 ] || die "no app sources found" "the check cannot run, which is not the same as passing"

scan() { # <pattern> -> file:line:match, or nothing
  grep -rnoEI "\\b${UTIL}$1" "${dirs[@]}" \
    --include='*.vue' --include='*.ts' --include='*.css' \
    --exclude-dir=node_modules --exclude-dir=.nuxt --exclude-dir=.output 2>/dev/null
}

fails=0
report() { # <hits> <what> <instead>
  [ -n "$1" ] || return 0
  log_fail "$(wc -l <<<"$1" | tr -d ' ') class(es) $2" "$3" || true
  sed "s|^$ROOT/||" <<<"$1" | head -20 | sed 's/^/      /' >&2
  fails=$((fails + 1))
}

report "$(scan "$PALETTE")" "use a raw palette colour" \
  "use a role: accent, canvas, surface, surface-subtle, fg, fg-muted, border, ok, warn, crit, info - see ui-kit/app/assets/css/main.css"
report "$(scan "$REMOVED")" "use a token the platform removed" \
  "brand-N is accent-N; surface-muted is canvas; surface-sunken is surface-subtle; danger, warning, success are crit, warn, ok"

[ "$fails" -eq 0 ] || exit 1
log_ok "every colour is a role or the accent scale"
