---
tags: [testing, phpunit, ci, flakiness]
date: 2026-11-05
phase: 9
---
# A test order that learns hides what it learned

## What happened
A project added a privacy test for Sentry error reports. The first `make ci` failed on it:
the report carried no request, so an assertion on `POST` failed. Run again on its own, run
with its suite, run through the same `make route-coverage` that had just failed - it passed
every time. It looked like a flaky test.

It was not flaky. In file order it failed every time, alone or not. The SDK installs each
integration once per process and keeps the first instance, so the request the second test
handed to its own integration never reached the event. Any order that ran the earlier test
first broke it.

## Why it happened
`backend/phpunit.dist.xml` had `executionOrder="depends,defects"`. `defects` reads the
result cache and runs the tests that failed last time first. A test that only fails when
another runs before it fails once, moves to the front, and passes from then on - on that
machine, until the cache is cleared. Its author's machine had seen it fail once and never
again, which is how it reached a pull.

## The rule
**The suite runs in one fixed order: `executionOrder="depends"`.** A test that depends on
running before or after another then fails on every run, for whoever wrote it.
`make test-order-check` refuses `defects`, and `random` and `duration` with it: an order that
changes between runs blames whoever runs next, not whoever wrote the dependency.
`make selftest` proves the check fires.

## How it was caught
By the one run with a cold result cache - a fresh checkout's first `make ci`. Reproduced
with `--order-by=default --do-not-cache-result`, which is now simply the default.

## Where else this applies
- Anything process-global that a test configures: SDK singletons, static registries,
  `setupOnce` hooks, `ini_set`, environment variables. Build the state the test needs in the
  test, or prove it is reset.
- "It passed when I ran it again" is a finding, not a resolution. Rerun with the cache off
  before calling anything flaky.
