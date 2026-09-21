#!/usr/bin/env bash
# =============================================================================
#  make module NAME=Billing [--no-frontend]
# =============================================================================
#  Scaffolds one feature across every place it must exist: the backend module,
#  the matching frontend layer, the docs, the spec stub and the Task Router row.
#
#  The point is not typing speed. It is that "where does this go?" has exactly
#  one answer, and that the harness grows WITH the code - a Task Router row and a
#  MODULE.md written at the end of a phase describe what someone remembers
#  building, which is worse than nothing.
#
#  Runs on the host, not in the container: it writes to backend/, frontend/,
#  AGENTS.md and .ai/, and the api container only mounts backend/. It also has to
#  work before the stack is up.
# =============================================================================
set -uo pipefail
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(cd "$HERE/../.." && pwd)"
. "$HERE/../lib/log.sh"

NAME="${NAME:-}"
WITH_FRONTEND=1
for arg in "$@"; do [ "$arg" = "--no-frontend" ] && WITH_FRONTEND=0; done

[ -n "$NAME" ] || die "NAME is required" \
  "usage: make module NAME=Billing [--no-frontend]" \
  "NAME is StudlyCase and becomes the PHP namespace segment (App\\Module\\Billing)."

grep -qE '^[A-Z][A-Za-z0-9]*$' <<<"$NAME" || die \
  "invalid NAME: '$NAME'" \
  "must match ^[A-Z][A-Za-z0-9]*\$ - StudlyCase, no underscores or dashes." \
  "It is used verbatim as a PHP namespace segment and as the directory name;" \
  "the module loader refuses a manifest whose name and directory disagree."

# billing_reports -> billing_reports ; BillingReports -> billing_reports
SLUG="$(sed -E 's/(.)([A-Z])/\1_\2/g' <<<"$NAME" | tr '[:upper:]' '[:lower:]')"
KEBAB="${SLUG//_/-}"
BACK="$ROOT/backend/src/Module/$NAME"
FRONT="$ROOT/frontend/app/modules/$KEBAB"
TODAY="$(date +%F)"

[ -d "$BACK" ] && die "backend/src/Module/$NAME already exists" \
  "delete it first, or pick another name"

log_step "Creating module $NAME"

# ── backend ─────────────────────────────────────────────────────────────────
mkdir -p "$BACK"/{Contract,Controller/Api,Command,Console,Dto,Entity,Repository,Service,Setup,Event,Listener,Message,Handler,Security/Voter,Acl,Migrations,Fixtures,i18n,Tests/Unit,Tests/Functional}

# Directories that are empty on day one still need to exist: the discovery pass
# keys off their presence, and an agent copying the layout must see the shape.
for d in Contract Command Console Dto Entity Repository Setup Event Listener Message Handler Security/Voter Migrations Fixtures Tests/Functional; do
  : > "$BACK/$d/.gitkeep"
done

cat > "$BACK/module.yaml" <<EOF
# Read by the kernel at container-compile time. The name MUST match the
# directory name - it is the PSR-4 namespace segment.
name: $NAME
description: TODO - one line on what this module owns.
depends: []
enabled: true
EOF

cat > "$BACK/MODULE.md" <<EOF
# $NAME

TODO - one paragraph: what this module owns, and what it deliberately does not.

> Budget: 8 KB. A module that needs more explaining than that is too big -
> split it rather than writing more here. Checked by \`make agents-budget\`.

## Owns

- TODO - the entities and behaviour that belong to this module alone.

## Public contracts

Other modules may import **only** what is listed here (from \`Contract/\`).
Everything else is internal and may change without notice.

- _(none yet)_

## Events

Events this module emits, which other modules may subscribe to. This list is
cross-checked against \`Event/\` by \`make docs:check\`.

- _(none yet)_

## Permissions

Declared in \`Acl/permissions.php\`:

- \`$SLUG.view\` - read access
- \`$SLUG.manage\` - create, update, delete

## Notes for agents

- Infrastructure this module needs (cache, storage, events, search, progress,
  flags) comes from the kernel - see \`.ai/platform/PLAN.md\` §6.9. Do not add a local version.
- Cross-module access goes through \`Contract/\` or an event, never a direct
  import. Enforced by \`make arch\`.
EOF

cat > "$BACK/Acl/permissions.php" <<EOF
<?php

declare(strict_types=1);

/**
 * Permissions this module defines.
 *
 * Permissions are global: the kernel refuses to boot if two modules declare the
 * same string, because a permission with two owners has no meaning.
 */
return [
    '$SLUG.view',
    '$SLUG.manage',
];
EOF

cat > "$BACK/Service/${NAME}Service.php" <<EOF
<?php

declare(strict_types=1);

namespace App\\Module\\$NAME\\Service;

/**
 * TODO - the business logic of this module.
 *
 * Services are the only place business rules live. Controllers validate and
 * delegate; repositories query; entities hold state.
 */
final class ${NAME}Service
{
    public function describe(): string
    {
        return '$SLUG';
    }
}
EOF

cat > "$BACK/Controller/Api/${NAME}Controller.php" <<EOF
<?php

declare(strict_types=1);

namespace App\\Module\\$NAME\\Controller\\Api;

use App\\Module\\$NAME\\Service\\${NAME}Service;
use Symfony\\Component\\HttpFoundation\\JsonResponse;
use Symfony\\Component\\Routing\\Attribute\\Route;
use Symfony\\Component\\Security\\Http\\Attribute\\IsGranted;

/**
 * Thin by rule: validate, delegate, serialize. No business logic, and no
 * EntityManager - both are build failures (\`make arch\`).
 *
 * Every action names the permission it needs. One without is a build failure
 * too: an endpoint reachable by any signed-in user of any role is an accident
 * nobody notices until it is a customer's data.
 */
final readonly class ${NAME}Controller
{
    public function __construct(private ${NAME}Service \$${SLUG}) {}

    #[Route('/api/$KEBAB', name: '${SLUG}_index', methods: ['GET'])]
    #[IsGranted('${SLUG}.view')]
    public function index(): JsonResponse
    {
        return new JsonResponse(['module' => \$this->${SLUG}->describe()]);
    }
}
EOF

cat > "$BACK/Tests/Unit/${NAME}ServiceTest.php" <<EOF
<?php

declare(strict_types=1);

namespace App\\Module\\$NAME\\Tests\\Unit;

use App\\Module\\$NAME\\Service\\${NAME}Service;
use PHPUnit\\Framework\\Attributes\\CoversClass;
use PHPUnit\\Framework\\TestCase;

#[CoversClass(${NAME}Service::class)]
final class ${NAME}ServiceTest extends TestCase
{
    public function testDescribeReturnsTheModuleSlug(): void
    {
        self::assertSame('$SLUG', (new ${NAME}Service())->describe());
    }
}
EOF

# A functional test from the first minute, because route coverage is MEASURED
# (\`make route-coverage\`): a scaffolded endpoint with no test fails the gate,
# and a module that starts red teaches the next person that red is normal.
cat > "$BACK/Tests/Functional/${NAME}ApiTest.php" <<EOF
<?php

declare(strict_types=1);

namespace App\\Module\\$NAME\\Tests\\Functional;

use App\\Module\\Identity\\Entity\\Membership;
use App\\Tests\\Support\\ApiTestCase;

/**
 * Real HTTP, real database. Assert content, never only a status code, and
 * assert the refusals - that is where most of a suite's value is.
 */
final class ${NAME}ApiTest extends ApiTestCase
{
    /** Entities wiped before each test, in deletion order. Add yours here. */
    protected function fixtures(): array
    {
        return [];
    }

    public function testTheIndexAnswersForASignedInMember(): void
    {
        \$this->givenATenant();
        \$this->givenIAmSignedIn(Membership::ROLE_MEMBER, 'member@example.test');

        \$this->get('/api/$KEBAB');

        self::assertResponseIsSuccessful();
        self::assertSame('$SLUG', \$this->json()['module']);
    }

    public function testTheIndexIsClosedWithoutASession(): void
    {
        \$this->givenATenant();

        \$this->get('/api/$KEBAB');

        self::assertResponseStatusCodeSame(401);
    }
}
EOF

# Both locales from the start: a key present in one and missing in the other
# fails `make i18n-check` (ADR-0020).
# Symfony resolves catalogues as <domain>.<locale>.<format>; the frontend uses
# Nuxt's <locale>.json. Each convention is native to its own side.
printf '{\n  "%s.title": "%s"\n}\n' "$SLUG" "$NAME" > "$BACK/i18n/messages.en.json"
printf '{\n  "%s.title": "%s"\n}\n' "$SLUG" "$NAME" > "$BACK/i18n/messages.pl.json"

# Verify what we just wrote actually parses. A generator emitting a syntax
# error is worse than no generator: the failure surfaces later, somewhere else,
# as an unrelated-looking 500.
if command -v php >/dev/null 2>&1; then
  bad=0
  while IFS= read -r f; do
    php -l "$f" >/dev/null 2>&1 || { log_fail "generated file does not parse: ${f#$ROOT/}"; php -l "$f" 2>&1 | sed 's/^/      /' >&2; bad=1; }
  done < <(find "$BACK" -name '*.php')
  [ "$bad" -eq 0 ] || die "the generator produced invalid PHP - this is a bug in module.sh"
  log_ok "generated PHP parses"
fi

log_ok "backend/src/Module/$NAME"

# ── frontend ────────────────────────────────────────────────────────────────
if [ "$WITH_FRONTEND" -eq 1 ]; then
  mkdir -p "$FRONT"/{pages,components,composables,stores,types}/ "$FRONT/pages/$KEBAB" "$FRONT/i18n/locales"
  for d in components composables stores types; do : > "$FRONT/$d/.gitkeep"; done

  cat > "$FRONT/nuxt.config.ts" <<EOF
// A module is a Nuxt layer, so its pages, components and composables register
// themselves - and deleting this directory removes the feature cleanly, with no
// dangling imports left behind (ADR-0010).
//
// Imported rather than relied on as a global: this file lives under the app's
// srcDir, so it is type-checked in the app project, where Nuxt's config globals
// are not declared.
import { defineNuxtConfig } from 'nuxt/config'

export default defineNuxtConfig({
  // Deliberately no \`srcDir\`. \`extends\` merges a layer's config into the app, so
  // setting it here would silently become the APP's srcDir, and the app's own
  // pages/ and middleware/ would stop being scanned - no error, just a router
  // that matches nothing.

  i18n: {
    // \`<module>/i18n/locales\` is where @nuxtjs/i18n looks by default, so a
    // feature keeps its translations beside its pages rather than in one file
    // every branch edits. The loaders point at the .ts wrappers, never the
    // .json - see i18n/locales/en.ts for why.
    locales: [
      { code: 'en', files: ['en.ts'] },
      { code: 'pl', files: ['pl.ts'] },
    ],
  },
})
EOF

  cat > "$FRONT/navigation.ts" <<EOF
import { defineNavigation } from '@ui-kit/composables/defineNavigation'

// Menu entries this module contributes. Merged and permission-filtered by the
// shell - an extension surface, so nothing central needs editing.
export default defineNavigation([
  {
    label: '$SLUG.title',
    to: '/$KEBAB',
    permission: '$SLUG.view',
  },
])
EOF

  cat > "$FRONT/pages/$KEBAB/index.vue" <<EOF
<script setup lang="ts">
// Auto-registered at /$KEBAB by the layer - no re-export shim needed.
// The path comes from the directory, so pages live under pages/$KEBAB/.
definePageMeta({ middleware: 'permission', permission: '$SLUG.view' })

const { t } = useI18n()
</script>

<template>
  <div class="flex flex-col gap-6">
    <!-- TODO: real UI. Strings go through i18n keys; raw text fails lint.
         frontend/app/modules/example/pages/example/index.vue is the screen to copy. -->
    <h1 class="text-lg font-semibold text-fg">{{ t('$SLUG.title') }}</h1>
  </div>
</template>
EOF

  printf '{\n  "%s.title": "%s"\n}\n' "$SLUG" "$NAME" > "$FRONT/i18n/locales/en.json"
  printf '{\n  "%s.title": "%s"\n}\n' "$SLUG" "$NAME" > "$FRONT/i18n/locales/pl.json"

  for loc in en pl; do
    cat > "$FRONT/i18n/locales/$loc.ts" <<EOF
// The catalogue is the .json next door; this file only re-exports it.
//
// Registering the .json directly makes Vite's json plugin wrap the output of
// @nuxtjs/i18n's own transform in JSON.parse(), which breaks server rendering.
// Pointing the loader at a .ts keeps the data in a file the parity check can
// read while taking it out of that plugin's path.
import messages from './$loc.json'

export default messages
EOF
  done

  log_ok "frontend/app/modules/$KEBAB"
fi

# ── spec stub ───────────────────────────────────────────────────────────────
# A project's spec goes to .ai/specs/, which no platform update touches; a module
# added to the platform itself is specified with the platform (ADR-0023).
# .project.json says which this is.
SPEC_DIR=".ai/specs"
if [ "$(sed -n 's/.*"initialized"[[:space:]]*:[[:space:]]*\(true\|false\).*/\1/p' "$ROOT/.project.json" | head -1)" = false ]; then
  SPEC_DIR=".ai/platform/specs"
fi
SPEC="$ROOT/$SPEC_DIR/${TODAY}-${KEBAB}.md"
if [ ! -f "$SPEC" ]; then
  cat > "$SPEC" <<EOF
# $NAME

**Status:** draft · **Date:** $TODAY

## Problem

TODO - what is not possible today, and for whom.

## Approach

TODO - the chosen design, in a paragraph.

## Rejected

TODO - what else was considered and why not. This section is the one that saves
the next person from re-proposing it.

## Surface

- API paths: TODO
- UI paths: TODO
- Permissions: \`$SLUG.view\`, \`$SLUG.manage\`
- Events emitted: TODO

## Tests that ship with it

- [ ] Unit: TODO
- [ ] Functional: every route above
- [ ] E2E: only if it touches something functional tests cannot see

## Changelog

- $TODAY - scaffolded by \`make module NAME=$NAME\`
EOF
  # A project's specs are indexed, and check-docs fails on one that is not - so
  # the stub arrives with its row. (The table is the last thing in the index.)
  if [ "$SPEC_DIR" = ".ai/specs" ]; then
    printf '| [%s](%s) | %s |\n' "$NAME" "${TODAY}-${KEBAB}.md" "$TODAY" >> "$ROOT/$SPEC_DIR/README.md"
  fi
  log_ok "$SPEC_DIR/${TODAY}-${KEBAB}.md"
fi

# ── Task Router row ─────────────────────────────────────────────────────────
AGENTS="$ROOT/AGENTS.md"
MARKER="<!-- module-rows: make module inserts here, keep this comment -->"
ROW="| $NAME - $(printf '%s' "$SLUG") | \`backend/src/Module/$NAME/MODULE.md\` |"

if grep -qF "$MARKER" "$AGENTS"; then
  if grep -qF "backend/src/Module/$NAME/MODULE.md" "$AGENTS"; then
    log_skip "Task Router row already present"
  else
    tmp="$(mktemp)"
    awk -v marker="$MARKER" -v row="$ROW" '
      index($0, marker) { print row; print; next }
      { print }
    ' "$AGENTS" > "$tmp" && mv "$tmp" "$AGENTS"
    log_ok "AGENTS.md Task Router row"
  fi
else
  log_warn "no module-rows marker in AGENTS.md - add the Task Router row by hand:"
  log_info "$ROW"
fi

# ── next steps ──────────────────────────────────────────────────────────────
log_step "Next"
log_info "1. describe it:      $BACK/module.yaml  and  MODULE.md"
log_info "2. write the spec:   $SPEC_DIR/${TODAY}-${KEBAB}.md"
log_info "3. confirm wiring:   make modules"
log_info "4. add an entity, then:  make diff && make migrate"
log_info "5. verify:           make check"
log_info "6. the new route changed the contract:  make openapi types inventory"
log_info "7. before proposing it:  make ci"
