# ADR-0022 - CORS is an exact-origin allowlist, named by the URLs the apps are served at

**Status:** accepted · **Date:** 2026-09-11

## Context
The subdomain layout puts the tenant app, the operator console and the API on three
different hosts (ADR-0003), so **every** call the browser makes is cross-origin. That is a
deliberate consequence of the layout, and CORS is where it is paid for.

Two facts make the policy load-bearing rather than boilerplate:

- The refresh token is an `httpOnly` cookie (ADR-0006), so the API must answer with
  `Access-Control-Allow-Credentials: true` - which the browser refuses to accept alongside a
  wildcard origin. A wildcard is not merely unwise here; it does not work.
- With credentials allowed, the origin list is the only thing standing between a hostile
  page and an authenticated request made with the user's own cookies.

The bundle's own recipe ships `origin_regex: true` with a single comma-joined environment
variable. That combination is wrong twice: the comma-joined string is not a valid pattern,
and an unescaped `.` in `app.open-enu.local` also matches `appXopen-enu.local`, which
somebody can register.

## Decision
Exact matching, never regex. The allowlist is `APP_URL` and `MANAGER_URL` - the same two
variables `envgen` derives from `DOMAIN` and writes into the apps' own configuration - so
the origins a browser may call from cannot drift from the URLs the apps are actually served
at. There is deliberately no separate `CORS_ALLOW_ORIGIN`: a second variable holding the
same two values is a second place to forget.

Scoped to `^/api/` only. Signed storage URLs are fetched directly and carry no credentials,
so they need no CORS headers, and adding them would only widen the surface.

`If-Match` is allowed on the way in; `ETag`, `X-Request-Id` and `Retry-After` are exposed on
the way out. Those are not decoration - a header the browser does not expose is invisible to
JavaScript however present it is on the wire, and `ETag` is how the client learns the
version to send back on its next write.

## Consequences
- Adding a fourth origin (a mobile web build, a partner console) is a config change, and a
  deliberate one.
- A lookalike domain cannot slip through, because nothing is pattern-matched.
- Local development on a bare `localhost:3000` outside the Docker stack is not allowed by
  default. That is intentional: the stack serves the apps under real hostnames with trusted
  TLS precisely so dev and production differ as little as possible.
- The policy is asserted by `tests/Security/CrossOriginPolicyTest.php`, which checks the
  *absence* of the header for a third origin and for a lookalike. A browser test notices a
  policy that is too narrow the moment the app stops working, and never notices one that is
  too wide.
