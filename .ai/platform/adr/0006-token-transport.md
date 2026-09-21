# ADR-0006 - Access token in memory, refresh token in an httpOnly cookie

**Status:** accepted · **Date:** 2026-09-10

## Context
`localStorage` is the default in most Nuxt/SPA tutorials. It means an XSS in any dependency
exfiltrates a long-lived refresh token, and the attacker keeps access after the page closes.

## Decision
- Access token (15 min): browser memory only. Never `localStorage`, never a cookie.
- Refresh token (30 d): `httpOnly; Secure; SameSite=Strict` cookie scoped to `api.${DOMAIN}`;
  the manager's is additionally `Path=/api/manager`. DB-backed, revocable, rotated on use.
- Mercure subscriber token: `mercureAuthorization` cookie - `EventSource` cannot set headers.

`app.${DOMAIN}` → `api.${DOMAIN}` is same-site (site = eTLD+1), so `SameSite=Strict` works
without a `Domain=` attribute.

## Consequences
- XSS can act as the user while the page is open; it cannot steal a durable credential.
- CORS must be exact-origin with credentials - a wildcard is refused by browsers anyway.
- A page reload costs one refresh call rather than a login.
