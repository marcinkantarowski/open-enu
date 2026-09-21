---
tags: [cors, security, testing, browser]
date: 2026-09-11
phase: 5
---
# A test client never asks the browser's permission

## What happened
By the end of phase 4 the API had 100 passing tests, including a functional suite that drove
real HTTP against a real database, and a security suite covering realm isolation, tenant
scoping, token reuse and credential handling. All green.

The first time a browser tried to sign in, the form did nothing at all. No error in the
server log, no failed request in the API's access log, and this in the page:

```
Failed to fetch
```

There was no CORS configuration in the project. There never had been.

## Why it happened
Every test up to that point spoke to the application through Symfony's `KernelBrowser` or
through `curl`. Neither sends an `Origin` header, neither issues a preflight, and neither
cares what comes back in `Access-Control-Allow-Origin` - because CORS is not enforced by the
server. It is enforced by the browser, on the browser's side, using headers the server is
merely expected to supply.

So the whole class of failure was invisible to the entire test suite by construction, and
would have stayed invisible right up to the first real user.

It is also not a small gap here: the three apps live on three hosts (`app.`, `manager.` and
the API's own), so **every** call the frontend makes is cross-origin. The design guaranteed
this would matter, and nothing checked it.

## The rule
**If a behaviour is enforced by the client, a server-side test cannot cover it.** Name those
behaviours explicitly and put a browser in front of them.

In this repo that list is short and worth memorising: CORS, cookie attributes
(`httpOnly`, `SameSite`, `Domain`, `Path`), `EventSource` and its credentials, the
`multipart` boundary, and which response headers JavaScript is allowed to read.

## How it was caught
`make e2e`. Every one of the eleven specs failed at the login form, which is a loud enough
signal to be unmissable - and the reason the suite exists at all (.ai/platform/PLAN.md §13). The second
half of the fix was a PHP test that asserts the header is *absent* for a third origin:
a browser test notices a policy that is too narrow the moment the app stops working, and
never notices one that is too wide.

## Where else this applies
- `expose_headers`: `ETag` and `X-Request-Id` are on the wire in every response and
  invisible to JavaScript unless listed. The client reads `ETag` to know which version to
  send back on the next write - so omitting it silently disables optimistic locking from
  the browser's side while leaving every server-side test passing.
- `allow_headers`: the same, for `If-Match` on the way out.
- Anything added later that the browser gates: `Permissions-Policy`, `COEP`/`COOP`,
  `Content-Security-Policy`. None of them can fail a PHPUnit test.
