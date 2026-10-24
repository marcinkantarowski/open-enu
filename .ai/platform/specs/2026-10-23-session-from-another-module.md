# A session for a person another module authenticated

## Problem

Identity signs people in with an email and a password, and nothing else can start a session:
`LoginService` and `SessionIssuer` are internal, and the one contract,
`SessionMinterInterface`, mints only a short, non-refreshable impersonation token. A project
that knows its people by other means - a single sign-on provider, a link sent by mail, the
credentials of a system it replaced - would have to copy the session machinery into its own
module, or edit Identity.

## Approach

- **Backend.** `SessionMinterInterface::startSession(userId, tenantId): Response`. The calling
  module decides *who* the person is; Identity decides *what a session is*. The answer is the
  login's own response - token and session in the body, refresh token in a cookie - built by
  the same code: `LoginService::startFor()` shares everything after the password check with
  `authenticate()`, and `SessionPayload::response()` is the one place that response is made
  (the auth controller uses it too). An unknown or inactive user, or a tenant the user is not
  a member of, is a 401, as for a login.
- **Frontend.** `login.vue` renders `<InjectionPoint name="auth.login.methods">` below its form.
  The module registers a link to its own page there (`injections.ts`), and that page keeps the
  answer with `useAuthStore().setSession()`.
- The caller owns refusing guesses: it rate-limits its endpoint and answers every failure
  alike. Identity cannot do that for credentials it never sees.

## Rejected

- **A second contract and service** (`SessionStarterInterface`): the cleaner split, but
  Identity sits at its 40-file budget, and both methods are "a session for a user another
  module vouches for".
- **Returning the session array** instead of a `Response`: every caller would then rebuild
  the body and set the cookie, which is the "one shape for every session" rule written again
  by each module.
- **A Symfony authenticator per method**: the API has no session firewall to plug one into;
  a session here is a token pair issued by Identity.

## Tests

`AuthApiTest`: a started session answers with the login's keys, in the user's tenant, and
its refresh cookie renews it; an unverified user, an unknown user and a foreign tenant are
refused.

## Changelog

- 2026-10-23 - first version, for a project's sign-in with legacy credentials.
