# ADR-0010 - Each frontend module is a local Nuxt layer

**Status:** accepted · **Date:** 2026-09-10

## Context
A `modules/<name>/pages/` folder that Nuxt does not know about needs re-export shims in the
real `pages/` directory - boilerplate per page, and a dangling import every time a module is
deleted.

## Decision
Each `frontend/app/modules/<name>/` has its own `nuxt.config.ts` and is listed in the app's
`extends`. Pages, components and composables auto-register.

## Consequences
- Deleting a folder removes a feature cleanly.
- Backend and frontend module names match one-to-one, so paths stay derivable.
- Layer resolution order becomes meaningful - documented in `.ai/platform/docs/frontend-conventions.md`.
