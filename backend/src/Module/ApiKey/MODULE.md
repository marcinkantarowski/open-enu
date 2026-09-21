# ApiKey

Owns machine credentials: scoped, revocable keys for clients that are not browsers.

## Owns

- `ApiKey` - hashed at rest, with the prefix kept in clear so a key is recognisable in a
  listing. Carries a **permission subset**, never "everything its creator can do".
- `ApiKeyAuthenticator` - claims `Authorization: Bearer sk_…` and establishes the tenant
  scope, because a key *is* the tenant claim; there is no session to read one from.
- `JwtExtractorGuard` - makes the key and JWT authenticators non-overlapping by
  construction, so which one claims a request never depends on compile order.

## Public contracts

_(none yet.)_

## Events

_(none yet.)_

## Permissions

- `api_key.view`
- `api_key.manage` - owner-only, and no key is ever granted it: a credential that can mint
  credentials removes the point of scoping them.

## Notes for agents

- The secret exists in clear exactly once, in the creation response. It is not recoverable,
  which is the correct answer to "can you resend it?".
- `ApiKeyUser` is deliberately not a `User`. An integration is not a person, must not
  inherit one's permissions, and attributing its actions to whoever created the key makes
  the audit trail lie.
