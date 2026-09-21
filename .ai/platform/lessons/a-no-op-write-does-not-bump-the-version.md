---
tags: [doctrine, optimistic-locking, testing, e2e]
date: 2026-09-11
phase: 5
---
# A write that changes nothing does not bump the version

## What happened
The end-to-end conflict spec put two people on the same record, had the first rename it to
`Renamed by Alice`, then had the second save against the version they had read. The second
save is supposed to come back 409 with both versions and the current record.

It passed. Then it failed. Then it passed. Three identical runs, three different answers.

The API log told the story:

```
run 0   alice PATCH if-match="9"  → 200      bob PATCH if-match="9"  → 409   ✅
run 1   alice PATCH if-match="10" → 200      bob PATCH if-match="10" → 200   ❌
run 2   alice PATCH if-match="11" → 200      bob PATCH if-match="11" → 409   ✅
```

In run 1 both writers sent the *same* version and both were accepted.

## Why it happened
Run 0 left the record named `Renamed by Alice`. In run 1 Alice renamed it to
`Renamed by Alice` - the name it already had. Doctrine computes a changeset before it
flushes; an entity with no changed fields produces no `UPDATE`, and `#[ORM\Version]`
increments *only* on an actual update. The version stayed at 10, so the second writer's
"stale" validator was not stale, and the conflict never occurred.

Nothing was broken. The fixture was.

## The rule
**A test that asserts on a version must change the value it writes.** A constant in a
fixture is a value that is already there on the second run, and a no-op write is
indistinguishable from a successful one from the client's side.

The fix is one line - `const aliceName = \`Renamed by Alice ${Date.now()}\`` - and the
comment beside it is longer than the change, because the next person to write a
locking test will reach for a constant too.

## How it was caught
Not by the suite: it reported flakiness, which is the easiest signal in the world to
re-run and ignore. It was caught by replaying the scenario three times in a row with the
request headers and response codes printed. `if-match="10" → 200` twice in one run is not
something a screenshot shows.

## Where else this applies
- Any test of `If-Match` / ETag behaviour, and any test that asserts "the second writer is
  refused".
- Seeded data generally: `seedExamples()` is required to be idempotent, which means a
  re-seed writes the same values - so a test built on seeded values inherits this problem.
- It is also worth knowing in production terms: a client that PUTs an unchanged record
  does not consume a version, so "the version went up" is not a reliable way to detect
  that someone saved.
