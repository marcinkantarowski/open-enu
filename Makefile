# =============================================================================
#  OpenEnu - the single entry point for dev and deployment.
# =============================================================================
#  Every target carries a `## description` and `help` parses them, so the list
#  below can never drift from what is actually implemented. Targets appear here
#  as the phase that owns them lands (.ai/platform/PLAN.md §14) - an unimplemented target is
#  an absent target, not a stub that fails at 3am.
# =============================================================================

SHELL := /usr/bin/env bash
.SHELLFLAGS := -eu -o pipefail -c
.DEFAULT_GOAL := help

# The bootstrap chains must run in order; never let -j reorder them.
.NOTPARALLEL:

ROOT := $(patsubst %/,%,$(dir $(abspath $(lastword $(MAKEFILE_LIST)))))
LIB  := $(ROOT)/scripts/lib
DEV  := $(ROOT)/scripts/dev
REMOTE := $(ROOT)/scripts/remote

# .env is optional at this point: `make env` is what creates it.
-include .env
export

PROJECT_SLUG ?= open-enu
DOMAIN       ?= open-enu.local

# COMPOSE_FILE comes from .env: the dev file locally, dev + the staging override
# on a staging server. Never name the files here - see scripts/lib/compose.sh.
COMPOSE := docker compose --project-directory $(ROOT) --env-file $(ROOT)/.env \
           $(foreach f,$(subst :, ,$(or $(COMPOSE_FILE),docker/compose.dev.yml)),-f $(ROOT)/$(f))

# Everything that can write into the bind-mounted source runs as www-data, which
# the dev image remaps to your uid. Running as root instead leaves files you
# cannot edit - and console commands that generate code (migrations:diff,
# make:*) do exactly that.
APIRUN  := $(COMPOSE) exec -u www-data -e COMPOSER_HOME=/tmp/composer
API     := $(APIRUN) -T api
CONSOLE := $(APIRUN) api php bin/console

# ═════════════════════════════════════════════════════════════════════════════
##@ Setup
# ═════════════════════════════════════════════════════════════════════════════

.PHONY: init
init: ## Rename the boilerplate into your project (NAME=myproject [DOMAIN=...])
	@$(DEV)/init.sh

.PHONY: check-tools
check-tools: ## Verify the toolchain, split into needed-now and needed-to-deploy
	@$(DEV)/check-tools.sh

.PHONY: env
env: ## Create/repair .env and derive every per-app env file from it
	@$(LIB)/envgen.sh generate

.PHONY: env-check
env-check: ## Report .env drift against .env.example (read-only)
	@$(LIB)/envgen.sh check

.PHONY: module-check
module-check: ## Verify every module is structurally complete
	@$(DEV)/check-modules.sh

.PHONY: docs-check
docs-check: ## Verify docs link correctly and MODULE.md matches the code
	@$(DEV)/check-docs.sh

.PHONY: i18n-check
i18n-check: ## Verify every locale file has the same keys as its counterparts
	@$(DEV)/check-i18n.sh

.PHONY: shell-check
shell-check: ## ShellCheck every script - the shell is what runs against real servers
	@$(DEV)/check-shell.sh

.PHONY: prod-check
prod-check: ## Verify the production stack's invariants: no source mounts, no DB port, pinned images
	@$(DEV)/check-prod-compose.sh

.PHONY: route-coverage
route-coverage: test-db ## Every routed endpoint must be exercised by a functional or security test
	@$(DEV)/check-route-coverage.sh

.PHONY: inventory
inventory: ## Regenerate .ai/inventory.json - what already exists, for whoever adds the next thing
	@$(DEV)/inventory.sh

.PHONY: inventory-check
inventory-check: ## Fail if .ai/inventory.json no longer matches the code
	@$(DEV)/inventory.sh --check

.PHONY: dead-code
dead-code: ## Unused TypeScript (blocking) and unreachable PHP classes (advisory)
	@$(DEV)/check-dead-code.sh

.PHONY: typography-check
typography-check: ## Fail on a long dash (U+2014) anywhere - this project writes a plain hyphen
	@$(DEV)/check-typography.sh

.PHONY: seo-check
seo-check: ## Landing pages: titles, descriptions, headings, alt text, URL-safe filenames
	@$(DEV)/check-landing-seo.sh

.PHONY: symfony-check
symfony-check: ## Every Symfony component on the framework's line, fenced from the next major
	@$(DEV)/check-symfony-line.sh

.PHONY: agents-budget
agents-budget: ## Check AGENTS.md / MODULE.md context budgets
	@$(DEV)/agents-budget.sh

.PHONY: selftest
selftest: ## Verify init + envgen guardrails against a throwaway copy
	@$(DEV)/selftest.sh

# ═════════════════════════════════════════════════════════════════════════════
##@ Development
# ═════════════════════════════════════════════════════════════════════════════

.PHONY: builddev
builddev: check-tools env certs hosts up deps wait jwt migrate flags smoke urls ## Bring the whole stack up from nothing (idempotent)

.PHONY: certs
certs: ## Issue the locally-trusted wildcard certificate (and trust it on Windows under WSL2)
	@$(DEV)/certs.sh

.PHONY: jwt
jwt: ## Generate the JWT signing keypair (idempotent; never regenerates)
	@$(DEV)/jwt.sh

.PHONY: hosts
hosts: ## Map every ${DOMAIN} host to 127.0.0.1 (Linux and, under WSL2, Windows)
	@$(DEV)/hosts.sh

.PHONY: edge
edge: ## Start the shared edge proxy (one per Docker host) and register this project with it
	@$(DEV)/edge.sh up

.PHONY: ports
ports: ## Check that no other project or process holds a host port this stack publishes (read-only)
	@$(DEV)/check-ports.sh

.PHONY: up
up: ports edge ## Build images if needed and start every container
	@$(COMPOSE) up -d --build --remove-orphans
	@$(DEV)/edge.sh attach

.PHONY: wait
wait: ## Block until postgres, redis and the API report healthy
	@$(DEV)/wait.sh

.PHONY: deps
deps: ## Install composer and npm dependencies inside the containers
	@$(DEV)/deps.sh

.PHONY: smoke
smoke: ## Verify every host answers over trusted HTTPS
	@$(DEV)/smoke.sh

.PHONY: urls
urls: ## Print the URL table
	@$(DEV)/urls.sh

.PHONY: down
down: ## Stop and remove containers (volumes survive; the shared edge keeps running)
	@$(DEV)/edge.sh detach
	@$(COMPOSE) down --remove-orphans

.PHONY: stop
stop: ## Stop containers without removing them
	@$(COMPOSE) stop

.PHONY: restart
restart: ## Restart every container
	@$(COMPOSE) restart

.PHONY: rebuild
rebuild: ## Rebuild images from scratch and restart
	@$(COMPOSE) build --no-cache
	@$(COMPOSE) up -d --remove-orphans
	@$(DEV)/edge.sh attach

.PHONY: status
status: ## Show container status and health
	@$(COMPOSE) ps

# ═════════════════════════════════════════════════════════════════════════════
##@ Preflight
# ═════════════════════════════════════════════════════════════════════════════
#  Read-only, every one of them. They diagnose and they generate fixes; they
#  never change a DNS zone or a server as a side effect (.ai/platform/PLAN.md §10.5).

.PHONY: preflight
preflight: ## Everything a remote target needs, checked first: make preflight HOST=deploy@ip DOMAIN=x.com REPO=org/x
	@$(REMOTE)/preflight.sh "$(HOST)" "$(DOMAIN)" "$(REPO)" "$(or $(REF),main)"

.PHONY: dns-check
dns-check: ## Check DNS against the target and write importable fixes: make dns-check DOMAIN=x.com IP=1.2.3.4
	@$(REMOTE)/dns-check.sh "$(DOMAIN)" "$(or $(IP),$(HOST))" $(if $(TTL),--ttl $(TTL),) $(if $(DNS_MODE),--mode $(DNS_MODE),)

.PHONY: dns-apply
dns-apply: ## Apply the generated Cloudflare deltas - opt-in, never run by a build: CF_API_TOKEN=... make dns-apply DOMAIN=x.com
	@test -f .out/dns/$(DOMAIN)/cloudflare.sh || { echo "  run make dns-check first"; exit 1; }
	@bash .out/dns/$(DOMAIN)/cloudflare.sh --apply

.PHONY: deploy-key
deploy-key: ## Give a stage access to the repo (production read-only, STAGE=staging read-write): make deploy-key HOST=deploy@ip REPO=org/x [STAGE=staging]
	@$(REMOTE)/deploy-key.sh "$(HOST)" "$(REPO)" "$(or $(STAGE),production)"

# ═════════════════════════════════════════════════════════════════════════════
##@ Remote
# ═════════════════════════════════════════════════════════════════════════════

.PHONY: buildstaging
buildstaging: ## Bare server → staging (the dev stack, for vibe coding): make buildstaging HOST=deploy@ip DOMAIN=stg.x.com REPO=org/x EMAIL=you@x.com [ALLOW=ip,cidr]
	@$(REMOTE)/provision.sh "$(HOST)" "$(DOMAIN)" staging "$(REPO)" "$(or $(REF),main)"

.PHONY: buildprod
buildprod: ## Bare server → production, one command; same server as staging or its own (refuses to finish without BACKUP_REMOTE)
	@$(REMOTE)/provision.sh "$(HOST)" "$(DOMAIN)" production "$(REPO)" "$(or $(REF),main)"

.PHONY: deploystaging
deploystaging: ## Pull a ref into staging - refuses to touch uncommitted work there: make deploystaging HOST=deploy@ip DOMAIN=stg.x.com [REF=main]
	@$(REMOTE)/deploy.sh "$(HOST)" "$(DOMAIN)" staging "$(or $(REF),main)"

.PHONY: deployprod
deployprod: ## Ship a ref to production (runs `make ci` first; brief downtime). Usually: make promote
	@$(REMOTE)/deploy.sh "$(HOST)" "$(DOMAIN)" production "$(or $(REF),main)"

.PHONY: promote
promote: ## Production runs exactly what staging runs: make promote (on staging) | make promote STAGING=deploy@ip [PROD=deploy@ip]
	@STAGING="$(STAGING)" PROD="$(PROD)" $(REMOTE)/promote.sh

.PHONY: promote-gate
promote-gate: test-db arch test typecheck ## The gate `make promote` runs on staging before production moves

.PHONY: backup
backup: ## Take a production backup on the server now: make backup HOST=deploy@ip
	@ssh "$(HOST)" "cd /opt/$(PROJECT_SLUG)-prod && bash scripts/remote/backup.sh manual"

.PHONY: backup-verify
backup-verify: ## Restore the latest OFF-BOX backup into a scratch database and validate it
	@$(REMOTE)/backup-verify.sh "$(HOST)"

.PHONY: restore
restore: ## Put a backup back - destructive, asks for the database name: make restore HOST=deploy@ip [FILE=...]
	@$(REMOTE)/restore.sh "$(HOST)" "$(FILE)"

.PHONY: tunnel
tunnel: ## Forward a stage's database to localhost:15432 for one session: make tunnel HOST=deploy@ip [STAGE=staging]
	@# Postgres publishes no port on a server, so forward to the container itself.
	@ip=$$(ssh "$(HOST)" "docker inspect -f '{{range .NetworkSettings.Networks}}{{.IPAddress}}{{end}}' $(PROJECT_SLUG)-$(if $(filter staging,$(STAGE)),staging,prod)-postgres") \
	 && [ -n "$$ip" ] || { echo "  no $(PROJECT_SLUG)-$(if $(filter staging,$(STAGE)),staging,prod)-postgres container on $(HOST)"; exit 1; }; \
	 echo "  postgres://$(DB_USER)@127.0.0.1:15432/$(DB_NAME) - ctrl-c to close"; \
	 ssh -N -L 15432:$$ip:5432 "$(HOST)"

# ═════════════════════════════════════════════════════════════════════════════
##@ Logs & shells
# ═════════════════════════════════════════════════════════════════════════════

.PHONY: logs
logs: ## Follow logs from every container
	@$(COMPOSE) logs -f --tail=100

.PHONY: logs-api
logs-api: ## Follow API logs
	@$(COMPOSE) logs -f --tail=100 api

.PHONY: logs-ui
logs-ui: ## Follow frontend, manager and landing logs
	@$(COMPOSE) logs -f --tail=50 frontend manager landing

.PHONY: logs-frontend logs-manager logs-landing
logs-frontend: ## Follow frontend logs
	@$(COMPOSE) logs -f --tail=100 frontend
logs-manager: ## Follow manager logs
	@$(COMPOSE) logs -f --tail=100 manager
logs-landing: ## Follow landing logs
	@$(COMPOSE) logs -f --tail=100 landing

.PHONY: logs-traefik
logs-traefik: ## Follow the shared edge proxy's logs (every project on this host)
	@$(DEV)/edge.sh logs

.PHONY: shell
shell: ## Open a shell in the API container
	@$(COMPOSE) exec api bash

.PHONY: shell-ui
shell-ui: ## Open a shell in the frontend container
	@$(COMPOSE) exec frontend sh

.PHONY: psql
psql: ## Open psql on the development database
	@$(COMPOSE) exec postgres psql -U $(DB_USER) $(DB_NAME)

.PHONY: redis
redis: ## Open redis-cli
	@$(COMPOSE) exec redis redis-cli -a $(REDIS_PASSWORD) --no-auth-warning

.PHONY: composer
composer: ## Run composer in the API container as your user: make composer CMD="require foo/bar"
	@$(APIRUN) api composer $(CMD)

.PHONY: fix-perms
fix-perms: ## Give every file in the source tree back to your user
	@$(COMPOSE) exec -u root api chown -R www-data:www-data /app
	@echo "  ✓ backend/ is yours again"

.PHONY: console
console: ## Run a Symfony console command: make console CMD="debug:router"
	@$(CONSOLE) $(CMD)

.PHONY: modules
modules: ## Show what module discovery resolved (routes, entities, migrations, permissions)
	@$(CONSOLE) app:module:list $(if $(V),-v,)

.PHONY: module
module: ## Scaffold a feature end to end: backend module, frontend layer, docs, spec, Task Router row
	@$(DEV)/module.sh

# ═════════════════════════════════════════════════════════════════════════════
##@ Quality
# ═════════════════════════════════════════════════════════════════════════════

.PHONY: stan
stan: ## Static analysis (level 8) and the architecture rules
	@$(API) php vendor/bin/phpstan analyse --no-progress

.PHONY: test
test: ## Every PHP test suite
	@$(API) php vendor/bin/phpunit

.PHONY: test-kernel
test-kernel: ## The framework's own tests, including proof the arch rules fire
	@$(API) php vendor/bin/phpunit --testsuite kernel

.PHONY: test-unit
test-unit: ## Module unit tests (no container, no database)
	@$(API) php vendor/bin/phpunit --testsuite unit

.PHONY: test-db
test-db: ## Create and migrate the isolated test database
	@$(CONSOLE) doctrine:database:create --env=test --if-not-exists --quiet || true
	@$(CONSOLE) doctrine:migrations:migrate --env=test --no-interaction --allow-no-migration --quiet \
	  || { printf '  test schema is stale (a migration was regenerated) - rebuilding\n'; $(MAKE) --no-print-directory test-db-reset; }

.PHONY: test-db-reset
test-db-reset: ## Drop and rebuild the test schema from scratch
	@$(COMPOSE) exec -T postgres psql -q -U $(DB_USER) $(DB_NAME)_test \
	  -c 'DROP SCHEMA public CASCADE; CREATE SCHEMA public;' \
	  -c 'CREATE EXTENSION IF NOT EXISTS pg_trgm;' >/dev/null
	@$(CONSOLE) doctrine:migrations:sync-metadata-storage --env=test --no-interaction --quiet
	@$(CONSOLE) doctrine:migrations:migrate --env=test --no-interaction --allow-no-migration --quiet
	@echo "  ✓ test schema rebuilt"

.PHONY: test-functional
test-functional: test-db ## Module functional tests (real HTTP, real database)
	@$(API) php vendor/bin/phpunit --testsuite functional

.PHONY: test-security
test-security: test-db ## The tenant-isolation and credential-handling suite
	@$(API) php vendor/bin/phpunit --testsuite security

.PHONY: test-arch
test-arch: ## Contract-coverage checks that need the container
	@$(API) php vendor/bin/phpunit --testsuite arch

.PHONY: typecheck
typecheck: ## Type-check all three Nuxt apps against the generated API types
	@$(DEV)/typecheck.sh

.PHONY: e2e-fixtures
e2e-fixtures: ## Create the accounts the browser suite signs in as (idempotent)
	@$(DEV)/e2e-fixtures.sh

.PHONY: e2e
e2e: e2e-fixtures ## Browser tests against the running dev stack (ci only, never check)
	@$(COMPOSE) --profile e2e run --rm e2e npx playwright test $(if $(SPEC),$(SPEC),)

.PHONY: openapi
openapi: ## Regenerate the committed OpenAPI spec
	@$(DEV)/openapi.sh write

.PHONY: openapi-check
openapi-check: ## Fail if the committed spec is stale
	@$(DEV)/openapi.sh check

.PHONY: types
types: openapi ## Regenerate the frontend types from the spec
	@$(COMPOSE) run --rm --no-deps --entrypoint sh frontend -c 'npm run -w @open-enu/ui-kit types'
	@echo "  ✓ ui-kit/types/api.d.ts"

.PHONY: arch
arch: stan test-kernel test-arch schema-check openapi-check module-check docs-check i18n-check typography-check seo-check symfony-check shell-check prod-check route-coverage inventory-check dead-code ## Every architectural guardrail

.PHONY: check
check: ## The inner loop - run after every edit (target: under 60s)
	@$(DEV)/check.sh

.PHONY: ci
ci: check-tools env-check arch test-db test smoke typecheck e2e ## The full gate; what CI runs

# ═════════════════════════════════════════════════════════════════════════════
##@ Database
# ═════════════════════════════════════════════════════════════════════════════

.PHONY: migrate
migrate: ## Run pending migrations
	@$(CONSOLE) doctrine:migrations:migrate --no-interaction --allow-no-migration

.PHONY: diff
diff: ## Generate a migration into a module: make diff MODULE=Identity
ifndef MODULE
	@printf '  MODULE is required: make diff MODULE=Identity\n\n'
	@printf '  Doctrine registers one migration namespace per module. Asked without\n'
	@printf '  one it silently picks the first alphabetically, so a migration for\n'
	@printf '  Identity lands in ApiKey and nobody notices until the module is\n'
	@printf '  removed and takes unrelated tables with it.\n\n'
	@printf '  Modules: %s\n' "$$(ls $(ROOT)/backend/src/Module | tr '\n' ' ')"
	@exit 1
endif
	@$(CONSOLE) app:module:diff $(MODULE)

.PHONY: migrations
migrations: ## List migration status across every module
	@$(CONSOLE) doctrine:migrations:list

.PHONY: db-reset
# Drops the SCHEMA rather than using doctrine:schema:drop, which can only drop
# what Doctrine currently maps - it leaves orphaned tables from a deleted module
# behind, and those are exactly what makes `make schema-check` fail.
db-reset: ## Drop every table and re-run migrations from scratch (dev only)
	@printf '  This DROPS the development database.\n'
	@read -p "  Type the project slug ($(PROJECT_SLUG)) to confirm: " a; \
	 [ "$$a" = "$(PROJECT_SLUG)" ] || { echo "  aborted"; exit 1; }; \
	 $(COMPOSE) exec -T postgres psql -q -U $(DB_USER) $(DB_NAME) \
	   -c 'DROP SCHEMA public CASCADE; CREATE SCHEMA public;' \
	   -c 'CREATE EXTENSION IF NOT EXISTS pg_trgm;'; \
	 $(CONSOLE) doctrine:migrations:sync-metadata-storage --no-interaction; \
	 $(CONSOLE) doctrine:migrations:migrate --no-interaction --allow-no-migration

.PHONY: flags
flags: ## Reconcile every module's declared feature flags into the database (idempotent)
	@$(CONSOLE) app:flags:sync

.PHONY: seed
seed: ## Run every module's TenantSetupInterface::seedExamples (idempotent)
	@$(CONSOLE) app:tenant:seed $(if $(TENANT),--tenant=$(TENANT),)

.PHONY: schema-check
schema-check: ## Fail if the mapping and the database schema disagree
	@$(CONSOLE) doctrine:schema:validate

# ═════════════════════════════════════════════════════════════════════════════
##@ Housekeeping
# ═════════════════════════════════════════════════════════════════════════════

.PHONY: clean
clean: ## Remove stopped containers and dangling images
	@docker system prune -f

.PHONY: clean-all
clean-all: ## Remove containers AND volumes - deletes the development database
	@printf '  This deletes the development database and every named volume.\n'
	@read -p "  Type the project slug ($(PROJECT_SLUG)) to confirm: " a; \
	 [ "$$a" = "$(PROJECT_SLUG)" ] || { echo "  aborted"; exit 1; }; \
	 $(DEV)/edge.sh detach; \
	 $(COMPOSE) down -v --remove-orphans

# ═════════════════════════════════════════════════════════════════════════════
##@ Meta
# ═════════════════════════════════════════════════════════════════════════════

.PHONY: info
info: ## Print this project's identity and host environment
	@$(DEV)/info.sh

.PHONY: help
help: ## Show this help
	@printf '\n  \033[1m%s\033[0m - %s\n\n' "$$(sed -n 's/.*"name"[[:space:]]*:[[:space:]]*"\([^"]*\)".*/\1/p' $(ROOT)/.project.json | head -1)" "make <target>"
	@awk 'BEGIN {FS = ":.*##"} \
		/^##@/ { printf "\n  \033[1m%s\033[0m\n", substr($$0, 5); next } \
		/^[a-zA-Z_0-9:-]+:.*?##/ { printf "    \033[36m%-18s\033[0m %s\n", $$1, $$2 }' \
		$(MAKEFILE_LIST) \
		| if [ "$$(sed -n 's/.*"initialized"[[:space:]]*:[[:space:]]*\(true\|false\).*/\1/p' $(ROOT)/.project.json | head -1)" = "false" ]; then cat; else grep -v 'Rename the boilerplate'; fi
	@printf '\n'
