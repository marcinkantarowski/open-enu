# Troubleshooting

Specific failures seen in this stack, with the cause and the fix. Symptoms first - that is
what you have when you arrive here.

---

## `make up` stops with "port 443 is held by the container '…'"

**Cause.** Routing belongs to the **edge** - one Traefik, `enu-edge`, shared by every project
on this Docker host (`docker/edge/`, `scripts/dev/edge.sh`). It needs host ports 80 and 443,
and something else already has them: usually another project's own Traefik, or a
`<slug>-traefik` container left over from before the edge existed.

**Fix.** Stop the container the message names, then `make up` again. A leftover project
Traefik can be removed for good: `docker rm -f <name>`.

---

## `make up` stops with "port 5432 is held by the container '…'"

**Cause.** Two projects on one Docker host. Names never collide - every container, network,
volume and router carries `${STACK}` - but a *published host port* is shared by the whole
machine, and every checkout defaults to `DB_PORT=5432`. `scripts/dev/check-ports.sh` reads
the published ports from the resolved compose config and runs before every `make up`.

**Fix.** Do what the message says: stop the named container, or give this project its own
port - the message names the `.env` variable and the next free port. Then `make env` and
`make up`. `make ports` runs the check alone and changes nothing.

---

## One project's hosts answer 504, or the wrong project answers

**Cause.** The edge sees every project's labels at once. Two things keep them apart, and
each fails in its own way:

- **The edge is not attached to the project's network** → 504. `make up` attaches it
  (`edge.sh attach`); a stack started with plain `docker compose up` never was.
- **Two projects share a router name** → Traefik drops both routers. Names must be
  `${PROJECT_SLUG}-<service>`; two checkouts with the same `PROJECT_SLUG` collide the same
  way, so give each its own with `make init`.

**Fix.** `make edge && scripts/dev/edge.sh attach`, then `make logs-traefik` for the router
list it loaded.

---

## `docker compose down` warns that the network "has active endpoints"

**Cause.** The edge is still attached to `<slug>-net`, so Docker refuses to delete the
network. `make down` and `make clean-all` detach it first; plain `docker compose down` does
not.

**Fix.** `scripts/dev/edge.sh detach`, then remove the network.

---

## Every host returns 404 from Traefik, but TLS is fine

**Cause.** Traefik cannot reach the Docker daemon, so it discovers no routers and falls
through to its 404. TLS still works because the certificate comes from the file provider,
which is independent - that is why this looks like a routing bug rather than a connection one.

```
make logs-traefik
ERR Failed to retrieve information of the docker client and server host
    error="Error response from daemon: " providerName=docker
```

The empty error message is the tell: the daemon rejected the API version outright.
Docker ≥ 29 sets a minimum API version (`docker version` → `MinAPIVersion`), and older
Traefik builds negotiate below it.

**Fix.** Pin a Traefik release new enough for the daemon. This repo runs `traefik:v3.7.9`;
v3.3 fails against Docker 29.

---

## The API returns HTML errors, or "Unable to create the cache directory"

**Cause.** `/app/var` and `/app/vendor` are named volumes. Docker creates a named volume
owned by `root` unless the image already contains that path with the ownership you want -
and a volume created *before* the image was fixed keeps its old ownership forever. php-fpm
runs as `www-data`.

**Fix.** Already handled: the API image pre-creates both paths owned by `www-data`, and
`docker/api/entrypoint.sh` re-chowns them at boot so pre-existing volumes self-heal. If you
see it anyway, the volume predates the entrypoint - `make down && make up` re-runs it.

---

## A container healthcheck fails while the service is demonstrably serving

**Cause.** `localhost` inside a container usually resolves to `::1` first. A server bound to
IPv4 `0.0.0.0` never sees that connection, and the probe reports "connection refused" for a
perfectly healthy process.

**Fix.** Probe `127.0.0.1`, never `localhost`. Both the Nuxt and API healthchecks do.

---

## The Nuxt apps 502 right after `make deps` or a restart

**Cause.** A Nuxt dev server takes 10–20 seconds to compile on boot. Anything that runs
immediately after a restart races it.

**Fix.** Already handled: the three app services have healthchecks with a `start_period`,
`make wait` blocks on them, and `builddev` is ordered `up → deps → wait → smoke`. Never add
a `sleep` - wait on the healthcheck.

---

## Hot reload stops working (the page never updates)

Two independent causes, both silent:

1. **The HMR websocket cannot connect.** It is proxied through Traefik over TLS, so the
   client must dial `wss://<host>:443`, not `ws://<host>:3000`. Set by `VITE_HMR_PROTOCOL`,
   `VITE_HMR_HOST` and `VITE_HMR_PORT` in compose, consumed by the ui-kit layer. Check the
   browser console for a failing websocket to `:3000`.
2. **File events do not cross the bind mount.** inotify is unreliable into containers on
   WSL2 and macOS, which is why the layer sets `server.watch.usePolling`. If reloads stop,
   confirm polling is still enabled before looking anywhere else.

Also check `fs.inotify.max_user_watches` on the host - `make check-tools` reports it. Below
~256k, watchers fail silently with three apps running.

---

## The browser shows a certificate warning (WSL2)

**Cause.** mkcert trusts its CA for the platform it runs on. The Linux `mkcert -install`
trusts it for Linux; the browser is a Windows application reading the Windows store.
Running `mkcert.exe -install` does not help - it would create and trust a *different* CA,
while the certificate is signed by the Linux one.

**Fix.** `make certs` installs the Linux CA into the Windows user store with `certutil.exe`.
If it could not, it prints the exact command. Verify with:

```powershell
certutil -user -verifystore Root <sha1-fingerprint>
```

---

## The browser cannot resolve the hosts, but `curl` can (WSL2)

**Cause.** Two hosts files matter and they serve different clients: Linux `/etc/hosts` for
curl and this repo's scripts, the Windows hosts file for the browser.

**Fix.** `make hosts` writes both, elevating via UAC for the Windows one. If sudo was
declined, the Linux half is skipped and the block is printed for manual entry - the stack
still works in the browser.

---

## `make builddev` asks for a password twice

Expected. `sudo` for `/etc/hosts`, and a Windows UAC prompt for the Windows hosts file.
Both are the `hosts` step. Everything else is non-interactive.

---

## Files created by the container are owned by root and you cannot edit them

**Cause.** The source tree is bind-mounted. Anything the container writes - composer
packages, generated migrations, flex config - lands on the host owned by whoever wrote it,
and php-fpm's user was `root`.

**Fix.** Already handled: the dev image remaps `www-data` to the host uid/gid (passed as
build args from `HOST_UID`/`HOST_GID`, refreshed by `make env`), and every Make target that
touches the source runs `-u www-data`. If you exec by hand, do the same:

```bash
docker compose exec -u www-data api php bin/console ...
```

`make fix-perms` repairs a tree that already went wrong.

---

## `doctrine:schema:validate` says the schema is out of sync and you changed nothing

Two distinct causes:

1. **Infrastructure tables.** `doctrine_migration_versions` and `messenger_messages` are
   owned by Doctrine Migrations and Messenger, not by any entity, so a naive comparison
   reports them as unexpected forever. `doctrine.dbal.schema_filter` excludes them.
2. **An orphaned table from a deleted module.** Dropping a module removes its mapping but
   not its tables. `doctrine:schema:drop` will not help - it can only drop what Doctrine
   currently maps. `make db-reset` drops the schema at the SQL level and re-migrates.
