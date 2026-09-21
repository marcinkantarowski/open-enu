# Lessons

One file per correction, written when a mistake is *understood* - not when it is made.
Read the index; open only the rows that match the task. Never bulk-read this directory.

A lesson earns a file when it would not be obvious to a competent engineer reading the
code, and when it is likely to recur. Everything else belongs in a code comment.

These are the **platform's** lessons: they ship with it and are updated with it. A project
built on the platform keeps its own in [`.ai/lessons/`](../../lessons/README.md) and never
edits these - see [`.ai/README.md`](../../README.md).

| Lesson | When it bites |
|---|---|
| [Sentinel strings must not be renameable](sentinel-strings-must-not-be-renameable.md) | Writing any tool that rewrites the tree it lives in - `init`, codemods, `make module` templates |
| [Status codes alone are not a health check](status-codes-alone-are-not-a-health-check.md) | Writing any smoke test, health endpoint or deploy gate |
| [A generator must validate its own output](a-generator-must-validate-its-own-output.md) | Writing anything that emits code - scaffolders, codemods, migration generators |
| [A guardrail firing on your own code is a decision point](a-guardrail-firing-on-your-own-code-is-a-decision-point.md) | Any new architecture rule - it WILL catch something legitimate on its first run |
| [An encryption key must belong to the row](an-encryption-key-must-belong-to-the-row.md) | Encrypting a column, signing an artefact, or anything whose key varies by context |
| [Test a contract against the real implementation](test-contracts-against-the-real-implementation.md) | Whenever a collaborator constrains the values you pass it - caches, storage, transports |
| [A layer's config is the app's config](a-layers-config-is-the-apps-config.md) | Setting anything path-shaped in a Nuxt layer - a module's `nuxt.config.ts` |
| [A no-op write does not bump the version](a-no-op-write-does-not-bump-the-version.md) | Testing optimistic locking, `If-Match`, or anything that asserts "the second writer is refused" |
| [A test client never asks the browser's permission](a-test-client-never-asks-for-permission.md) | Any behaviour the BROWSER enforces - CORS, cookie attributes, SSE credentials, exposed headers |
| [A mechanism with no way to create its inputs is untested by construction](a-mechanism-with-no-way-in-is-untested-by-construction.md) | Building anything read far more often than written - flags, settings, registries, catalogues |
| [A handler cannot record its own failure](a-handler-cannot-record-its-own-failure.md) | Writing anything that must survive a failure - delivery logs, retry counters, error reports |
| [An ambient dependency breaks its legitimate callers](an-ambient-dependency-breaks-its-legitimate-callers.md) | Adding anything to a Doctrine listener, or reading the tenant from scope on a write |
| [Unflushed work does not survive a scope crossing](unflushed-work-does-not-survive-rununscoped.md) | Calling anything that uses `runUnscoped()`, or any `$em->clear()`, with entities you still mean to save |
