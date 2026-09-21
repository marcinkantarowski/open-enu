# Environment variables

**`.env` at the repository root is the only file edited by hand.** Every per-app env file
is derived from it by `make env`, so hostnames, CORS origins and Mercure URLs cannot
disagree - the API's allowed origins *are* the URLs the apps are served at, named by the
same two variables.

```
.env.example   committed, documents every variable with a comment and a safe default
.env           gitignored, chmod 600, the one you edit
   ↓ make env  (scripts/lib/envgen.sh)
backend/.env.local   frontend/.env   manager/.env   landing/.env   e2e/.env
```

Every derived file says `GENERATED` on line 1. Editing one is pointless - the next
`make env` overwrites it - and it is a `Never` in `AGENTS.md` for that reason.

---

## The two invariants

**An existing value is never overwritten.** This is what makes `make buildprod` safe to
re-run against a live server: `APP_SECRET` would invalidate every session,
`APP_ENCRYPTION_KEY` would make every encrypted column unreadable *permanently*.

**A variable added upstream surfaces as a message, not as a 3am crash.** `make env-check`
diffs `.env` against `.env.example` and reports what is missing; it runs as the first step
of every build target.

```bash
make env         # create or repair .env, then derive everything from it
make env-check   # read-only: what has drifted
```

---

## Adding one

1. Add it to `.env.example`, **with a comment** saying what it does and a safe default.
   Undocumented variables fail `make env-check`.
2. If a container or an app needs it, add it to the matching block in
   `scripts/lib/envgen.sh`. Derived files are written there and nowhere else.
3. Use it:
   - PHP: `%env(MY_THING)%` in config, bound to a constructor argument in the kernel
     extension if the kernel owns it.
   - Nuxt: `NUXT_PUBLIC_*` for anything the browser may see - and only that. Everything
     under `NUXT_PUBLIC_` is compiled into the client bundle.
4. `make env && make check`.

`__GENERATE__` as the value in `.env.example` means "fill this with 32 random bytes on
first run". Use it for anything secret; never ship a real default.

---

## What the important ones do

| Variable | Effect |
|---|---|
| `DOMAIN` | drives every host: apex, `app.`, `manager.`, `api.` (which also serves Mercure at `/.well-known/mercure`) |
| `PROJECT_NAME` / `PROJECT_SLUG` | set by `make init`, not by hand |
| `APP_ENV` | Symfony's environment: only `dev`, `prod` or `test` |
| `APP_STAGE` | *which* prod-like deployment this is: `local`, `staging`, `prod` |
| `APP_SECRET` | sessions and signed URLs. Rotating it logs everyone out |
| `APP_ENCRYPTION_KEY` | the root of every derived per-tenant key. **Losing it loses the data** |
| `BACKUP_REMOTE` | an rclone target. Production refuses to finish provisioning without one |

`APP_ENV` and `APP_STAGE` are separate on purpose: staging runs `APP_ENV=prod` so Symfony's
`when@prod` configuration keeps applying, while `APP_STAGE=staging` drives `noindex`
headers, demo seeding and the Sentry environment tag. See
[ADR-0011](../adr/0011-app-stage-not-app-env.md).

Optional integrations are no-ops when empty - `SENTRY_DSN`, `BACKUP_REMOTE` - because a
boilerplate must boot with zero external accounts. The exception is mail: dev falls back to
Mailpit, production refuses to start without a real DSN, because silently dropping
registration emails is worse than not starting.

---

## Secrets, honestly

They are environment variables. That means they are visible to `docker inspect` and to any
process inside the container. That is an accepted trade-off for a single-host Compose
deployment, not an oversight: a project that needs more moves to Docker secrets or a vault,
and `scripts/lib/envgen.sh` is the single place that changes.

Nothing secret is ever committed: `.env*`, `docker/certs/`, `backend/config/jwt/`,
`docker/traefik/acme/` and `.out/` are all gitignored.

---

## On a server

`make buildprod HOST=… DOMAIN=…` generates the remote `.env` from the domain on the server
itself - secrets are created there and never leave it, and never travel through your
laptop. Re-running regenerates nothing that already exists. See
[`deployment.md`](deployment.md).
