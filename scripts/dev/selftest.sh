#!/usr/bin/env bash
# =============================================================================
#  Phase 0 guardrails - the harness checking itself.
# =============================================================================
#  .ai/platform/PLAN.md's thesis is that every rule which matters is also a check that fails.
#  `make init` and `envgen` are rules: init must rename the app and never the
#  kernel; envgen must never rotate a live secret. Both are one careless edit
#  away from silently doing the opposite, and neither has a test suite yet
#  because there is no application yet. So they get this.
#
#  Runs against a throwaway copy in a temp dir - never touches the checkout.
# =============================================================================
set -uo pipefail
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(cd "$HERE/../.." && pwd)"
. "$HERE/../lib/log.sh"

PASS=0; FAIL=0
ok()   { PASS=$((PASS+1)); printf '%s  ✓%s %s\n' "$C_GRN" "$C_RESET" "$1"; }
bad()  { FAIL=$((FAIL+1)); printf '%s  ✗ %s%s\n' "$C_RED$C_BOLD" "$1" "$C_RESET" >&2
         [ $# -gt 1 ] && printf '      expected: %s\n      actual:   %s\n' "$2" "${3:-<empty>}" >&2; return 0; }

# assert_eq <label> <expected> <actual>
assert_eq() { [ "$2" = "$3" ] && ok "$1" || bad "$1" "$2" "$3"; }
assert_contains() { grep -qF "$3" <<<"$2" && ok "$1" || bad "$1" "contains '$3'" "$2"; }

WORK="$(mktemp -d)"; trap 'rm -rf "$WORK"' EXIT
tar --exclude=.git --exclude=vendor --exclude=node_modules --exclude=.idea \
    --exclude='.out' -C "$ROOT" -cf - . | tar -C "$WORK" -xf -
cd "$WORK" || die "cannot enter the throwaway copy"

# Every name the framework defines outside its own source tree: the bundle in
# bundles.php, the route loader type, service tags, container parameters,
# cookies. init once rewrote three of them, because they contained the slug,
# and the renamed application no longer booted - while every assertion below
# about .project.json still passed.
FRAMEWORK_IDS='open_enu[._][a-z]|openEnu\.[a-z]|OpenEnuKernel|OpenEnu\\+Kernel|open-enu/(kernel|ui-kit)'
framework_ids() {
  grep -rhoE --exclude-dir=node_modules --exclude-dir=vendor "$FRAMEWORK_IDS" . 2>/dev/null | sort | uniq -c
}
FRAMEWORK_IDS_BEFORE="$(framework_ids)"

j() { php -r '$j=json_decode(file_get_contents($argv[1]),true); $v=$j; foreach(array_slice($argv,2) as $k){ $v = $v[$k] ?? ""; } echo is_bool($v) ? var_export($v,true) : $v;' "$@"; }

# ═══════════════════════════════════════════════════════════════════════════
log_step "make init - renames the application"
# ═══════════════════════════════════════════════════════════════════════════
make init NAME=acme-crm DOMAIN=acme.example.com >/dev/null 2>&1 || bad "init exited non-zero"

assert_eq "project slug rewritten"    "acme-crm"         "$(j .project.json slug)"
assert_eq "project name studly-cased" "AcmeCrm"          "$(j .project.json name)"
assert_eq "domain rewritten"          "acme.example.com" "$(j .project.json domain)"
assert_eq "initialized flag flipped"  "true"             "$(j .project.json initialized)"
assert_eq "env PROJECT_SLUG"          "acme-crm"         "$(sed -n 's/^PROJECT_SLUG=//p' .env.example)"
assert_eq "env DOMAIN"                "acme.example.com" "$(sed -n 's/^DOMAIN=//p' .env.example)"
assert_eq "root npm package renamed"  "acme-crm"         "$(j package.json name)"

# ═══════════════════════════════════════════════════════════════════════════
log_step "make init - must NOT rename the kernel (the upgrade path)"
# ═══════════════════════════════════════════════════════════════════════════
# If any of these fail, every project built from this checkout has silently lost
# the ability to pull kernel updates. This is the single most important check
# in Phase 0. See .ai/platform/adr/0016-kernel-packaging.md.
assert_eq "kernel composer package"   "open-enu/kernel"  "$(j backend/kernel/composer.json name)"
assert_eq "app requires the kernel"   "@dev"             "$(j backend/composer.json require open-enu/kernel)"
assert_eq "ui-kit npm package"        "@open-enu/ui-kit" "$(j ui-kit/package.json name)"
assert_contains "kernel PSR-4 prefix"  "$(php -r '$j=json_decode(file_get_contents("backend/kernel/composer.json"),true); echo array_key_first($j["autoload"]["psr-4"]);')" 'OpenEnu\Kernel\'
assert_eq "Kernel.php namespace"      "namespace OpenEnu\\Kernel;" "$(grep '^namespace' backend/kernel/src/Kernel.php)"
assert_eq "Kernel::NAME constant"     "open-enu/kernel"  "$(grep -oP "NAME = '\K[^']+" backend/kernel/src/Kernel.php)"
assert_eq "kernel source untouched"   "0" "$(grep -rlF 'acme' backend/kernel/ 2>/dev/null | wc -l)"
assert_contains "bundles.php names the kernel bundle" "$(cat backend/config/bundles.php)" 'OpenEnu\Kernel\OpenEnuKernelBundle::class'
assert_contains "routes.yaml uses the kernel's loader" "$(cat backend/config/routes.yaml)" 'type: open_enu_modules'

if [ "$(framework_ids)" = "$FRAMEWORK_IDS_BEFORE" ]; then
  ok "every framework identifier outside the kernel survived"
else
  bad "init rewrote a framework identifier outside the kernel" \
    "identical counts" "$(diff <(echo "$FRAMEWORK_IDS_BEFORE") <(framework_ids) | grep '^[<>]' | head -5 | tr '\n' ' ')"
fi

# ═══════════════════════════════════════════════════════════════════════════
log_step "make init - database identifiers stay SQL-safe"
# ═══════════════════════════════════════════════════════════════════════════
# A dashed slug is legal in a URL and illegal in an unquoted SQL identifier;
# the dash surfaces later in psql one-liners and backup filenames nobody quotes.
assert_eq "DB_NAME underscored" "acme_crm" "$(sed -n 's/^DB_NAME=//p' .env.example)"
assert_eq "DB_USER underscored" "acme_crm" "$(sed -n 's/^DB_USER=//p' .env.example)"
assert_eq "manifest db field"   "acme_crm" "$(j .project.json db)"

# ═══════════════════════════════════════════════════════════════════════════
log_step "make init - idempotent, and the sentinel survives its own rename"
# ═══════════════════════════════════════════════════════════════════════════
out="$(make init NAME=acme-crm DOMAIN=acme.example.com 2>&1)"
assert_contains "second run is a no-op" "$out" "nothing to do"

# The bug this guards: a sentinel built from the string "open-enu" is rewritten
# by the very rename it is meant to detect, inverting its meaning.
out="$(make info 2>&1)"
if grep -q "not initialized yet" <<<"$out"; then
  bad "make info still calls a renamed project uninitialized" "no warning" "warning present"
else
  ok "make info knows the project is initialized"
fi

out="$(make help 2>&1)"
if grep -q "Rename the boilerplate" <<<"$out"; then
  bad "make help still advertises init after renaming" "init hidden" "init listed"
else
  ok "make help hides init after renaming"
fi

# ═══════════════════════════════════════════════════════════════════════════
log_step "envgen - never rotates a live secret"
# ═══════════════════════════════════════════════════════════════════════════
# This is what makes `make buildprod` safe to re-run against a live server:
# regenerating APP_SECRET would invalidate every session, and regenerating
# APP_ENCRYPTION_KEY would make every encrypted column unreadable, permanently.
./scripts/lib/envgen.sh generate >/dev/null 2>&1
before_secret="$(sed -n 's/^APP_SECRET=//p' .env)"
before_enc="$(sed -n 's/^APP_ENCRYPTION_KEY=//p' .env)"
./scripts/lib/envgen.sh generate >/dev/null 2>&1
assert_eq "APP_SECRET preserved"         "$before_secret" "$(sed -n 's/^APP_SECRET=//p' .env)"
assert_eq "APP_ENCRYPTION_KEY preserved" "$before_enc"    "$(sed -n 's/^APP_ENCRYPTION_KEY=//p' .env)"

if [ -n "$before_secret" ] && [ "$before_secret" != "__GENERATE__" ]; then
  ok "secrets were actually generated"
else
  bad "secrets not generated" "random value" "$before_secret"
fi

# ═══════════════════════════════════════════════════════════════════════════
log_step "envgen - surfaces an upstream variable instead of crashing later"
# ═══════════════════════════════════════════════════════════════════════════
echo 'NEW_UPSTREAM_KEY=default' >> .env.example
out="$(./scripts/lib/envgen.sh generate 2>&1)"
assert_contains "new variable reported" "$out" "NEW_UPSTREAM_KEY"
assert_eq "new variable added to .env" "default" "$(sed -n 's/^NEW_UPSTREAM_KEY=//p' .env)"

sed -i '/^NEW_UPSTREAM_KEY/d' .env
out="$(./scripts/lib/envgen.sh check 2>&1 || true)"
assert_contains "env-check detects the gap" "$out" "NEW_UPSTREAM_KEY"

# ═══════════════════════════════════════════════════════════════════════════
log_step "envgen - derived files agree with each other"
# ═══════════════════════════════════════════════════════════════════════════
sed -i '/^NEW_UPSTREAM_KEY/d' .env.example
./scripts/lib/envgen.sh generate >/dev/null 2>&1
# APP_URL and MANAGER_URL *are* the CORS allowlist - nelmio_cors names them
# directly, so the origins a browser may call from cannot drift from the URLs
# the apps are served at.
assert_contains "backend CORS names the app origin"     "$(cat backend/.env.local)" "APP_URL=https://app.acme.example.com"
assert_contains "backend CORS names the manager origin" "$(cat backend/.env.local)" "MANAGER_URL=https://manager.acme.example.com"
assert_contains "frontend API base is the API host"  "$(cat frontend/.env)"      "https://api.acme.example.com"
assert_contains "manager shares the API host"        "$(cat manager/.env)"       "https://api.acme.example.com"
assert_contains "backend DSN uses the SQL-safe name" "$(cat backend/.env.local)" "/acme_crm?"
assert_contains "derived files are marked generated" "$(head -1 frontend/.env)"  "GENERATED"

# ═══════════════════════════════════════════════════════════════════════════
log_step "Guardrails actually fail when the rule is broken"
# ═══════════════════════════════════════════════════════════════════════════
# The whole premise of this repository is that every rule which matters is also
# a check that fails. A check that has never been SEEN to fail is not evidence
# of anything - Phase 1 shipped a smoke test that reported a dead API as healthy
# for exactly that reason. So each guardrail is broken on purpose here, and must
# report it.
#
# The PHPStan architecture rules are proved separately and more precisely, by
# RuleTestCase in kernel/tests/PHPStan/ - run by `make test-kernel`.

# Everything a fixture below is allowed to vandalise. Snapshotted before each
# check and restored after, so one broken fixture cannot leak into the next
# check and fail it for the wrong reason - which is exactly what happened the
# first time a compose-file fixture was added here.
RESTORABLE=(backend/src/Module backend/composer.json backend/composer.lock AGENTS.md docker/compose.prod.yml docker/compose.staging.yml scripts/dev .ai .project.json landing/content)

# fails_when <label> <check-script> <break-command>
fails_when() {
  local label="$1" check="$2" breakage="$3"
  local snapshot; snapshot="$(mktemp -d)"

  local path
  for path in "${RESTORABLE[@]}"; do
    mkdir -p "$snapshot/$(dirname "$path")"
    cp -r "$WORK/$path" "$snapshot/$path" 2>/dev/null || true
  done

  bash -c "$breakage" >/dev/null 2>&1

  if "$check" >/dev/null 2>&1; then
    bad "$label" "the check to FAIL" "it passed - the guardrail does not work"
  else
    ok "$label"
  fi

  for path in "${RESTORABLE[@]}"; do
    rm -rf "${WORK:?}/$path"
    cp -r "$snapshot/$path" "$WORK/$path" 2>/dev/null || true
  done
  rm -rf "$snapshot"
}

# A module needs something to break, and the copy under test may have none.
# A module needs something to break, and this deliberately uses a THROWAWAY
# one rather than Example: the fixtures below corrupt it on purpose, and a
# half-broken copy of the reference module is the last thing a reader should
# stumble into if a run is interrupted.
NAME=Scratch "$WORK/scripts/dev/module.sh" >/dev/null 2>&1

fails_when "module-check catches a missing MODULE.md" \
  "$WORK/scripts/dev/check-modules.sh" \
  "rm -f '$WORK/backend/src/Module/Scratch/MODULE.md'"

fails_when "module-check catches a name/directory mismatch" \
  "$WORK/scripts/dev/check-modules.sh" \
  "sed -i 's/^name: Scratch/name: Renamed/' '$WORK/backend/src/Module/Scratch/module.yaml'"

fails_when "module-check catches an invented directory" \
  "$WORK/scripts/dev/check-modules.sh" \
  "mkdir -p '$WORK/backend/src/Module/Scratch/Helpers'"

fails_when "module-check catches a module missing from the Task Router" \
  "$WORK/scripts/dev/check-modules.sh" \
  "sed -i '/backend\/src\/Module\/Scratch\/MODULE.md/d' '$WORK/AGENTS.md'"

fails_when "i18n-check catches a key present in one locale only" \
  "$WORK/scripts/dev/check-i18n.sh" \
  "printf '{\"scratch.title\":\"Scratch\",\"scratch.only_en\":\"x\"}' > '$WORK/backend/src/Module/Scratch/i18n/messages.en.json'"

# check-i18n deliberately skips a directory whose base catalogue is absent, so a
# WRONGLY-NAMED file is invisible to it. That gap is covered by check-modules,
# which requires the Symfony name - assert the division of labour holds, or a
# renamed catalogue would pass both checks while translating nothing.
fails_when "module-check catches a mis-named catalogue that i18n-check cannot see" \
  "$WORK/scripts/dev/check-modules.sh" \
  "mv '$WORK/backend/src/Module/Scratch/i18n/messages.en.json' '$WORK/backend/src/Module/Scratch/i18n/en.json'"

fails_when "docs-check catches a link to a file that does not exist" \
  "$WORK/scripts/dev/check-docs.sh" \
  "printf '\n[gone](./does-not-exist.md)\n' >> '$WORK/backend/src/Module/Scratch/MODULE.md'"

fails_when "docs-check catches an undocumented public contract" \
  "$WORK/scripts/dev/check-docs.sh" \
  "printf '<?php\nnamespace App\\\\Module\\\\Scratch\\\\Contract;\ninterface SecretReaderInterface {}\n' > '$WORK/backend/src/Module/Scratch/Contract/SecretReaderInterface.php'"

# ADR-0023: .ai/platform/ is the platform's, everything else under .ai/ is the
# project's. The copy under test has been through `init`, so it is a project.
scratch_specs="$(find "$WORK/.ai/specs" "$WORK/.ai/platform/specs" -name '*-scratch.md' 2>/dev/null)"
case "$scratch_specs" in
  "$WORK/.ai/specs/"*) ok "make module writes a project's spec to .ai/specs/" ;;
  *) bad "make module writes a project's spec to .ai/specs/" "a *-scratch.md stub there" "$scratch_specs" ;;
esac

fails_when "docs-check catches a project record missing from its index" \
  "$WORK/scripts/dev/check-docs.sh" \
  "printf '# Stray\n' > '$WORK/.ai/lessons/stray.md'"

fails_when "docs-check catches a project record in the platform's repository" \
  "$WORK/scripts/dev/check-docs.sh" \
  "sed -i 's/\"initialized\": true/\"initialized\": false/' '$WORK/.project.json'"

fails_when "seo-check catches a page with no usable description" \
  "$WORK/scripts/dev/check-landing-seo.sh" \
  "sed -i 's/^description: .*/description: Too short./' '$WORK/landing/content/en/contact.md'"

fails_when "seo-check catches a second h1 in a page body" \
  "$WORK/scripts/dev/check-landing-seo.sh" \
  "printf '\\n# Another title\\n' >> '$WORK/landing/content/pl/privacy.md'"

fails_when "symfony-check catches a component on the next major" \
  "$WORK/scripts/dev/check-symfony-line.sh" \
  "sed -i '0,/\"name\": \"symfony\/var-exporter\"/{n;s/\"version\": \"v7\.4\.[0-9]*\"/\"version\": \"v8.0.16\"/}' '$WORK/backend/composer.lock'"

fails_when "symfony-check catches a component with no conflict fence" \
  "$WORK/scripts/dev/check-symfony-line.sh" \
  "sed -i '/\"symfony\/var-exporter\": \">=8.0\",/d' '$WORK/backend/composer.json'"

# The character is built from bytes here too, or this file would fail the check
# it is proving.
fails_when "typography-check catches a long dash" \
  "$WORK/scripts/dev/check-typography.sh" \
  "printf 'one \\342\\200\\224 two\\n' >> '$WORK/AGENTS.md'"

fails_when "prod-check catches a published database port" \
  "$WORK/scripts/dev/check-prod-compose.sh" \
  "sed -i 's|^  postgres:|  postgres:\\n    ports: [\"5432:5432\"]|' '$WORK/docker/compose.prod.yml'"

fails_when "prod-check catches an unpinned image" \
  "$WORK/scripts/dev/check-prod-compose.sh" \
  "sed -i 's|image: redis:7.4-alpine|image: redis:latest|' '$WORK/docker/compose.prod.yml'"

fails_when "prod-check catches a router with no certificate resolver" \
  "$WORK/scripts/dev/check-prod-compose.sh" \
  "sed -i '0,/tls.certresolver=letsencrypt/{/tls.certresolver=letsencrypt/d}' '$WORK/docker/compose.prod.yml'"

fails_when "prod-check catches a staging stack that publishes the database port" \
  "$WORK/scripts/dev/check-prod-compose.sh" \
  "sed -i '/ports: !reset \\[\\]/d' '$WORK/docker/compose.staging.yml'"

fails_when "prod-check catches a staging router outside the allowlist" \
  "$WORK/scripts/dev/check-prod-compose.sh" \
  "sed -i '/routers.\\\${STACK}-api.middlewares/d' '$WORK/docker/compose.staging.yml'"

fails_when "prod-check catches a script that drops the staging override" \
  "$WORK/scripts/dev/check-prod-compose.sh" \
  "printf '#!/usr/bin/env bash\\ndocker compose -f docker/compose.dev.yml up -d\\n' > '$WORK/scripts/dev/rogue-fixture.sh'"

fails_when "shell-check catches a broken script" \
  "$WORK/scripts/dev/check-shell.sh" \
  "printf '#!/usr/bin/env bash\\ncd \$1\\n' > '$WORK/scripts/dev/broken-fixture.sh'"

fails_when "agents-budget catches an oversized MODULE.md" \
  "$WORK/scripts/dev/agents-budget.sh" \
  "head -c 9000 /dev/zero | tr '\\0' 'x' >> '$WORK/backend/src/Module/Scratch/MODULE.md'"

# And the clean tree must still pass - a check that fails on everything is no
# more useful than one that fails on nothing.
for c in check-modules check-docs check-i18n check-typography check-landing-seo check-symfony-line agents-budget check-prod-compose; do
  if "$WORK/scripts/dev/$c.sh" >/dev/null 2>&1; then
    ok "$c passes on a clean tree"
  else
    bad "$c fails on a clean tree" "pass" "fail"
    "$WORK/scripts/dev/$c.sh" 2>&1 | sed 's/^/      /' >&2
  fi
done

# ═══════════════════════════════════════════════════════════════════════════
echo
if [ "$FAIL" -eq 0 ]; then
  log_ok "$PASS checks passed"
  exit 0
fi
log_fail "$FAIL of $((PASS+FAIL)) checks failed"
exit 1
