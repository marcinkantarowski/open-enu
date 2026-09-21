#!/usr/bin/env bash
# =============================================================================
#  make init NAME=myproject [DOMAIN=myproject.local]
# =============================================================================
#  Turns the boilerplate into a named project: compose project and container
#  names, database name and user, app titles, docs, default domain.
#
#  What it deliberately does NOT touch - the whole reason this script exists
#  rather than a `sed -i` across the tree (.ai/platform/PLAN.md §2, ADR-0016):
#
#      open-enu/kernel        composer package
#      OpenEnu\Kernel\        PHP namespace  (raw and JSON-escaped forms)
#      OpenEnuKernel*         the bundle and its extension, named in app config
#      @open-enu/ui-kit       npm package
#      backend/kernel/        the package's own source tree
#
#  Everything else the framework names - container parameters, service tags,
#  the route loader type, cookies, PHPStan identifiers - is spelled open_enu_*,
#  open_enu.* or openEnu.*, which contain neither the slug nor the studly name
#  and so cannot be reached by the replacement at all. A framework identifier
#  that DOES contain `open-enu` or `OpenEnu` is a bug: init will rename it apart
#  from the kernel code that still uses the old spelling. `make selftest` counts
#  them before and after.
#
#  Those are the FRAMEWORK's identity, not the project's. Keeping them stable
#  is what lets a project later swap the path repository for a versioned
#  `open-enu/kernel` tag and `composer update` it, instead of hand-merging
#  kernel fixes forever.
#
#  Idempotent: running it twice, or on an already-named project, is a reported
#  no-op. Token replacement is exact-string, never a pattern, so it cannot
#  half-rename something.
# =============================================================================
set -euo pipefail
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(cd "$HERE/../.." && pwd)"
# shellcheck source=../lib/log.sh
. "$HERE/../lib/log.sh"

MANIFEST="$ROOT/.project.json"
[ -f "$MANIFEST" ] || die ".project.json is missing" "this does not look like a OpenEnu checkout"

NEW_SLUG="${NAME:-}"
[ -n "$NEW_SLUG" ] || die "NAME is required" \
  "usage: make init NAME=myproject [DOMAIN=myproject.com]" \
  "NAME must be lowercase letters, digits and dashes (it becomes the DB name," \
  "the compose project name and the container prefix)."

grep -qE '^[a-z][a-z0-9-]{1,30}$' <<<"$NEW_SLUG" || die \
  "invalid NAME: '$NEW_SLUG'" \
  "must match ^[a-z][a-z0-9-]{1,30}\$ - start with a letter, then lowercase" \
  "letters, digits or dashes. It is used verbatim as a PostgreSQL identifier."

# ── current identity ────────────────────────────────────────────────────────
read_manifest() { sed -n "s/.*\"$1\"[[:space:]]*:[[:space:]]*\"\([^\"]*\)\".*/\1/p" "$MANIFEST" | head -1; }
OLD_SLUG="$(read_manifest slug)"
OLD_NAME="$(read_manifest name)"
OLD_DOMAIN="$(read_manifest domain)"

# ── target identity ─────────────────────────────────────────────────────────
# my-project -> MyProject
NEW_NAME="$(echo "$NEW_SLUG" | awk -F'-' '{for(i=1;i<=NF;i++) printf toupper(substr($i,1,1)) substr($i,2); print ""}')"
NEW_DOMAIN="${DOMAIN:-${NEW_SLUG}.local}"
# PostgreSQL identifiers: dashes are legal only when quoted, and they leak into
# DSNs, backup filenames and psql one-liners where nobody quotes them. Use an
# underscore form for anything that becomes a database identifier.
OLD_DB="$(read_manifest db)"; OLD_DB="${OLD_DB:-$OLD_SLUG}"
NEW_DB="${NEW_SLUG//-/_}"

if [ "$OLD_SLUG" = "$NEW_SLUG" ] && [ "$OLD_DOMAIN" = "$NEW_DOMAIN" ]; then
  log_skip "already initialized as '$NEW_SLUG' (${NEW_DOMAIN}) - nothing to do"
  exit 0
fi

log_step "Renaming project"
log_info "slug    ${OLD_SLUG}  ->  ${NEW_SLUG}"
log_info "name    ${OLD_NAME}  ->  ${NEW_NAME}"
log_info "domain  ${OLD_DOMAIN}  ->  ${NEW_DOMAIN}"
log_info "db      ${OLD_DB}  ->  ${NEW_DB}"
log_info ""
log_info "kernel  open-enu/kernel, OpenEnu\\Kernel\\, @open-enu/ui-kit  (unchanged by design)"

# ── protected strings: masked before replacement, restored after ────────────
# Sentinels use \x01, which cannot occur in source text.
readonly S=$'\001'
PROTECTED=(
  'open-enu/kernel'
  'OpenEnu\Kernel'
  'OpenEnu\\Kernel'
  'OpenEnu\\\\Kernel'   # the heredoc below, which writes the JSON-escaped form
  'OpenEnuKernel'
  '@open-enu/ui-kit'
  'open-enu/ui-kit'
)

build_sed_script() {
  local i=0 p
  # 1. mask
  for p in "${PROTECTED[@]}"; do
    printf 's|%s|%sP%d%s|g\n' "$(sed_escape "$p")" "$S" "$i" "$S"
    i=$((i + 1))
  done
  # 2. replace - domain first: it contains the slug as a substring.
  printf 's|%s|%s|g\n' "$(sed_escape "$OLD_DOMAIN")" "$(sed_escape "$NEW_DOMAIN")"
  printf 's|%s|%s|g\n' "$(sed_escape "$OLD_SLUG")"   "$(sed_escape "$NEW_SLUG")"
  printf 's|%s|%s|g\n' "$(sed_escape "$OLD_NAME")"   "$(sed_escape "$NEW_NAME")"
  # 3. unmask
  i=0
  for p in "${PROTECTED[@]}"; do
    printf 's|%sP%d%s|%s|g\n' "$S" "$i" "$S" "$(sed_escape "$p")"
    i=$((i + 1))
  done
}

sed_escape() { printf '%s' "$1" | sed -e 's/[\\/&|]/\\&/g'; }

SED_SCRIPT="$(mktemp)"; trap 'rm -f "$SED_SCRIPT"' EXIT
build_sed_script > "$SED_SCRIPT"

# ── walk the tree ───────────────────────────────────────────────────────────
mapfile -t FILES < <(
  find "$ROOT" \
    \( -path '*/.git' -o -path '*/node_modules' -o -path '*/vendor' \
       -o -path "$ROOT/backend/kernel" -o -path "$ROOT/.out" \
       -o -path "$ROOT/docker/certs" -o -path '*/.nuxt' -o -path '*/.output' \
       -o -path "$ROOT/backend/var" -o -path '*/.idea' \) -prune -o \
    -type f \( -name '*.php'  -o -name '*.json' -o -name '*.yaml' -o -name '*.yml' \
            -o -name '*.ts'   -o -name '*.js'   -o -name '*.mjs'  -o -name '*.vue' \
            -o -name '*.md'   -o -name '*.sh'   -o -name '*.neon' -o -name '*.dist' \
            -o -name '*.xml'  -o -name '*.html' -o -name '*.conf' -o -name '*.css' \
            -o -name 'Makefile' -o -name 'Dockerfile*' -o -name '.env.example' \
            -o -name '.env' \) \
    -print | sort
)

changed=0
for f in "${FILES[@]}"; do
  [ "$f" = "$MANIFEST" ] && continue        # rewritten explicitly below
  grep -qF -e "$OLD_SLUG" -e "$OLD_NAME" -e "$OLD_DOMAIN" "$f" 2>/dev/null || continue
  before="$(cksum < "$f")"
  sed -i -f "$SED_SCRIPT" "$f"
  [ "$(cksum < "$f")" != "$before" ] && { changed=$((changed + 1)); log_info "  ${f#$ROOT/}"; }
done

# ── paths carrying the old slug ─────────────────────────────────────────────
renamed=0
while IFS= read -r p; do
  [ -n "$p" ] || continue
  np="$(dirname "$p")/$(basename "$p" | sed "s|$OLD_SLUG|$NEW_SLUG|g")"
  [ "$p" = "$np" ] && continue
  mv "$p" "$np"; renamed=$((renamed + 1)); log_info "  ${p#$ROOT/} -> ${np#$ROOT/}"
done < <(find "$ROOT" \( -path '*/.git' -o -path '*/node_modules' -o -path '*/vendor' \
           -o -path "$ROOT/backend/kernel" \) -prune -o \
         -name "*${OLD_SLUG}*" -print 2>/dev/null | sort -r)

# ── database identifiers (underscore form, never the dashed slug) ───────────
for envf in "$ROOT/.env.example" "$ROOT/.env"; do
  [ -f "$envf" ] || continue
  sed -i -e "s|^DB_NAME=.*|DB_NAME=${NEW_DB}|" -e "s|^DB_USER=.*|DB_USER=${NEW_DB}|" "$envf"
done
log_ok "database identifiers set to '${NEW_DB}'"

# ── manifest ────────────────────────────────────────────────────────────────
# `initialized` is a BOOLEAN, deliberately not a comparison against the string
# "open-enu": init rewrites that string everywhere, so any sentinel built from it
# inverts its own meaning the moment it works. (It did. See .ai/platform/lessons/.)
cat > "$MANIFEST" <<EOF
{
  "\$comment": "Identity of THIS project. Rewritten by \`make init NAME=...\`. The kernel package (open-enu/kernel, OpenEnu\\\\Kernel\\\\, @open-enu/ui-kit) is deliberately NOT listed here - it is the framework's name and never changes, which is what lets a project pull kernel updates later. See .ai/platform/PLAN.md §2 and .ai/platform/adr/0016-kernel-packaging.md.",
  "initialized": true,
  "slug": "${NEW_SLUG}",
  "name": "${NEW_NAME}",
  "domain": "${NEW_DOMAIN}",
  "db": "${NEW_DB}"
}
EOF

log_ok "rewrote ${changed} file(s), renamed ${renamed} path(s)"

# ── verify the kernel survived ──────────────────────────────────────────────
if grep -rqF 'open-enu/kernel' "$ROOT/backend/composer.json" 2>/dev/null; then
  log_ok "kernel package reference intact (open-enu/kernel)"
elif [ -f "$ROOT/backend/composer.json" ]; then
  log_fail "kernel package reference was damaged in backend/composer.json" \
    "this is a bug in init.sh - restore from git and report it"
  exit 1
fi

log_step "Next"
log_info "1. review the diff:            git diff"
log_info "2. regenerate env files:       make env"
log_info "3. bring the stack up:         make builddev"
