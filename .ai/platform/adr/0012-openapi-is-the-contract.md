# ADR-0012 - `openapi.json` is generated, committed and diffed

**Status:** accepted · **Date:** 2026-09-10

## Context
API docs treated as optional drift immediately. This repository also *relies* on them:
`.ai/platform/PLAN.md` §12.1 lists `openapi.json` as a machine-readable source of truth for agents, and
the frontend's TypeScript types are generated from it.

## Decision
`nelmio/api-doc-bundle` generates `backend/openapi.json` from route attributes. The file is
committed. CI regenerates and runs `git diff --exit-code`. Frontend types come from it via
`make types`, also diffed.

## Consequences
- A DTO change that is not reflected in the spec fails the build.
- A backend change that breaks the frontend is a type error, not a runtime surprise.
- Endpoints must carry enough attribute metadata to generate a useful spec.
