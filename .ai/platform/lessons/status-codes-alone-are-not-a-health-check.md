---
tags: [testing, verification, smoke]
date: 2026-09-11
phase: 1
---
# A status-code-only check reported a fatally broken API as healthy

## What happened
Phase 1's smoke test asserted HTTP status codes for all nine hosts. It went green:

```
✓ https://api.open-enu.local/health   200  API health
```

The API was not working at all. `vendor/` was an empty named volume, so
`public/index.php` died on `require_once .../autoload_runtime.php`. PHP printed the
warning and fatal error into the response body **with status 200**, because
`display_errors` writes them as content and nothing had set a status. The check saw 200
and reported success.

The failure was found only because the response body was read by hand, for an unrelated
reason.

## Why it happened
The check asserted the easiest observable thing rather than the thing that mattered.
"Did it respond?" is not "did it work?" - and the gap between them is precisely where a
misconfigured runtime lives, because a crashed process usually still returns *something*.

## The rule
**Every check asserts content, not just a status.** In this repo each smoke target carries
a substring the body must contain, so the API's check fails unless the response actually
contains `"status":"ok"`.

More generally: when adding a check, ask what a *broken* system would return. If the check
would pass on that, it is not a check.

## How it is enforced now
`scripts/dev/smoke.sh` takes `host | path | status | must-contain | label` per target, and
prints the first 160 bytes of the body when the substring is missing - so a failure says
what arrived instead, rather than only that something was wrong.

## Where else this applies
- Any future health, readiness or deploy gate.
- Phase 8's `buildprod` health gate, which decides whether a deploy is declared successful.
- The same instinct produced the Phase 0 bug in [[sentinel-strings-must-not-be-renameable]]:
  a check that could not fail. Verify that a new guardrail actually fails before trusting it.
