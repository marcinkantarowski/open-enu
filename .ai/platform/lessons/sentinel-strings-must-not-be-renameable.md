---
tags: [init, tooling, self-reference]
date: 2026-09-11
phase: 0
---
# A sentinel built from a renameable string inverts its own meaning

## What happened
`scripts/dev/info.sh` detected "this is still the un-renamed boilerplate" with:

```bash
[ "$SLUG" = open-enu ] && log_warn "still the boilerplate default - run: make init"
```

`make init NAME=acme-crm` rewrites the string `open-enu` across the tree - including inside
that line, which became `[ "$SLUG" = acme-crm ]`. The check then matched *only* after a
rename, so a freshly-renamed project was told it had not been renamed, and an un-renamed one
was told it was fine. Exactly backwards, and silently.

The same class of bug hid in the `Makefile`'s help filter.

## Why it happened
The sentinel and the thing being renamed were the same string. Any tool that rewrites source
will rewrite its own control logic if that logic is expressed in the strings it rewrites.

## The rule
**State that a rewriting tool depends on must live in a form the rewrite cannot express.**
A boolean, a file's presence, a checksum - not a copy of the string being replaced.

Here: `.project.json` carries `"initialized": true|false`, flipped by `init.sh`. No consumer
compares a slug to a literal.

## How it was caught
Only by running `make init` on a throwaway copy and reading the output. Nothing failed; the
message was just wrong. That is why `make selftest` now asserts the *post-rename* behaviour
of `make info` and `make help`, not only that files changed.

## Where else this applies
- `scripts/dev/init.sh`'s own `PROTECTED` array - masked, so it survives; verified by selftest.
- Any future codemod, scaffolder or `make module` template that inspects the tree it edits.
