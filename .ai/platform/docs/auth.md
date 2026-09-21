# Auth

Two realms, three ways in, one token shape. The pieces are small; what matters is which
piece is allowed to talk to which, and that is enforced rather than documented.

---

## The two realms

| Realm | Firewall | Identity table | Audience claim |
|---|---|---|---|
| Tenant application | `api` (`^/api`) | `identity_user` | `app` |
| Operator console | `manager_api` (`^/api/manager`) | `platform_manager` | `manager` |
| Machine client | `api`, via the API-key authenticator | `api_key` row | `api_key` |

Separate tables, not a role flag. An operator has no membership in any tenant and cannot
acquire one; a tenant user cannot be promoted into the operator realm by editing a column.

Both firewalls sign with the same key, which is why the `aud` claim is the thing that
actually isolates them - the kernel's `AudienceListener` refuses a token whose audience
does not match the firewall that received it. Two stateless firewalls sharing a signing key
are otherwise not isolated at all. See
[ADR-0007](../adr/0007-realm-isolation-by-audience.md).

Firewall order matters: `manager_api` is declared **before** `api`, or `^/api` claims the
operator requests and resolves them against the tenant user provider.

---

## Token transport

- **Access token** - in memory in the browser, never in `localStorage`, sent as
  `Authorization: Bearer`.
- **Refresh token** - an `httpOnly; Secure; SameSite=Strict` cookie, rotated on every use,
  with reuse detection: presenting a token that has already been exchanged invalidates the
  whole chain.

[ADR-0006](../adr/0006-token-transport.md) has the reasoning. The practical consequence for
the frontend is that **there is no session to render on the server** - `frontend` and
`manager` are SPAs for that reason, and `useApi()` owns the single shared refresh on 401.
Never call `fetch` or `$fetch` from a component.

---

## The public surface

`^/api/auth/(register|verify|login|refresh|logout|forgot-password|reset-password|switch-tenant)`
is `security: false` by necessity - these are how a session comes to exist - and therefore
rate-limited in the controller rather than by the firewall.

Everything on that surface is written to be uninformative:

- Registration answers **202**, not 201: the account exists and is unusable until the
  address is proved, and "created" would invite the client to try logging in.
- Forgot-password answers identically for a known and an unknown address. A form that
  behaves differently is a way to test whether somebody has an account here.
- Expired, unknown and already-used verification tokens produce **one** message. Telling
  them apart tells an attacker which links were real.

Security tokens (verification, reset, invitation) are stored as SHA-256 and exist in clear
only in the email. A lost reset link cannot be recovered by support, which is the correct
answer.

---

## Permissions

A permission is a string like `example.view`, declared by the module that enforces it in
`Acl/permissions.php`. Nothing central lists them; `ModuleRegistry::permissions()` collects
them at compile time, and an undeclared permission is **refused**, so a typo in an
`#[IsGranted]` attribute fails closed rather than opening an endpoint.

Roles map to permissions in exactly one place - `Identity\Service\PermissionResolver`:

| Role | May |
|---|---|
| `owner` | everything |
| `admin` | everything except `tenant.delete` and `api_key.manage` |
| `member` | anything ending in `.view` |

Both callers that need this answer use that class: the voter, which enforces it, and the
session payload, which tells the UI which buttons to render. Computing it twice would
drift, and the drift is silent in the worst direction - a control that renders and then
403s.

The role travels with the **workspace**, not the person: the same account can be an owner
in one tenant and a member in another ([ADR-0005](../adr/0005-membership-from-day-one.md)).

---

## Machine clients

An API key is `sk_` + 32 bytes, stored hashed, scoped to one tenant and to an explicit list
of permissions. The authenticator claims only bearers carrying the `sk_` prefix, so keys
and JWTs never fight over a token.

A key **is** the tenant claim - there is no session to read one from - and it can never
hold `api_key.manage`, because a credential that mints credentials removes the point of
scoping them.

```bash
make console CMD="app:apikey:create --tenant=acme --name=agent --permission=example.view"
```

That is also how an MCP client is set up; see [`agent-interfaces.md`](agent-interfaces.md).

---

## Impersonation

The one designed crossing between realms. An operator mints a short-lived,
**non-refreshable** token for a tenant user: no cookie is set, so the session expires in
fifteen minutes rather than renewing itself quietly.

Both identities are recorded on every audited write (`actorId` and `onBehalfOfId`), the
frontend shows a persistent banner, and actions marked
`#[DeniedUnderImpersonation(because: '…')]` are refused outright - support may look at
where a tenant's events go; support may not add a destination for them while wearing that
tenant's face. See [ADR-0008](../adr/0008-impersonation.md).

---

## Where to look

| Question | File |
|---|---|
| What a session holds | `backend/src/Module/Identity/Service/SessionPayload.php` |
| How a request is authenticated | `backend/config/packages/security.yaml` |
| Whether the realms are really isolated | `backend/tests/Security/RealmIsolationTest.php` |
| Whether tokens behave | `backend/tests/Security/CredentialHandlingTest.php` |
| The endpoints themselves | `backend/src/Module/Identity/Tests/Functional/AuthApiTest.php` |
