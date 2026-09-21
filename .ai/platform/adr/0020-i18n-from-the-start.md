# ADR-0020 - Two locales from the start, enforced by lint

**Status:** accepted · **Date:** 2026-09-10

## Context
i18n added later is a rewrite: every string is already inline, in components, exceptions,
validation messages and email templates. Adding it at the start costs a translation-key
call per string.

## Decision
`pl` and `en` ship from Phase 2. API messages resolve via `Accept-Language` → user
preference → tenant default → `en`. **Raw user-facing strings fail lint** - a PHPStan rule
for response bodies and `@intlify/eslint-plugin-vue-i18n` for components. `make i18n-check`
also fails when a key exists in one locale and not the other.

Translatable *entity fields* (per-tenant content in many languages) are **not** adopted -
that is a product feature, not a foundation one.

## Consequences
- Two locale files per module, scaffolded by `make module`.
- A third language is a file, not a project.
- Some friction on every string. That is the mechanism working.
