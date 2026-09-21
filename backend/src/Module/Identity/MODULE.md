# Identity

Owns people, their membership of tenants, and everything that authenticates one.

## Owns

- `User` - a person. **Not** tenant-scoped: a user belongs to several tenants (ADR-0005).
  The email is encrypted at rest with a deterministic `email_hash` beside it, which is what
  login queries - an encrypted column cannot be searched.
- `Membership` - a user's place in one tenant, with one role. Roles come from here, never
  from the user: "the roles of a user" is not a well-formed question.
- `SecurityToken` - verification, reset and invitation tokens. Hashed, single-use, expiring.
- `RefreshToken` - the long-lived half of a session. Hashed, rotated on use, with reuse
  detection that revokes the whole family.

## Public contracts

- `Contract\SessionMinterInterface` - mints an impersonation session for a user.
  The split matters: the Manager module decides *whether* an operator may impersonate;
  this module decides *what a session for a user looks like*. Manager never touches a `User`.
- `Contract\UserDirectoryInterface` - who belongs to a tenant, as rows. Used by the operator
  console to pick someone to view as.

## Events

_(none yet.)_

## Permissions

- `identity.member.view`
- `identity.member.invite`
- `identity.member.manage`
- `identity.profile.manage`

## Console

- `app:user:create <email> --tenant= --slug= --role= --password= --name= [--seed]` - creates
  a workspace and a **verified** user in it, idempotently. The bootstrap escape from the
  chicken-and-egg of signup: the first account on a fresh server, a developer thirty seconds
  after `make builddev`, and the end-to-end suite all need an account to exist before any
  browser opens. `--seed` also runs every module's example data for that workspace.

## Notes for agents

- **Permission rules live in `Service/PermissionResolver`, not in the voter.** Two callers
  need the same answer - the voter, which enforces it on every request, and
  `Service/SessionPayload`, which tells the UI which controls to render. A second copy
  drifts in the worst direction: a button that renders and then 403s.
- **Every endpoint that establishes a session returns the same shape** - login, refresh,
  switch-tenant and impersonation all go through `SessionPayload`, so the client has one
  code path for "I now have a session" rather than four that differ in small ways.
- `/api/profile` is under `/api/`, deliberately **not** `/api/auth/profile`: everything
  below `/api/auth/` is `PUBLIC_ACCESS` in `security.yaml`, because that prefix is the set
  of endpoints that establish a session. An authenticated route placed there would inherit
  that rule and be exposed.
- **Never query `email`.** It is ciphertext and differs on every write. Hash the address
  with `Encryptor::hashForLookup()` and query `emailHash`.
- Session machinery (`LoginService`, `SessionIssuer`) is `#[InfrastructureWrite]`, not
  commands: auditing every token rotation would bury the trail. Security events that
  matter - verification, password reset - *are* commands.
- Authentication failures return one indistinguishable answer. Telling "no such user" from
  "wrong password" turns login into an address oracle.
