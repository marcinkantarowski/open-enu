# ADR-0023 - The platform's knowledge lives in `.ai/platform/`; the rest of `.ai/` is the project's

**Status:** accepted · **Date:** 2026-09-21

## Context
This repository is a platform that other projects are started from, and ADR-0016 keeps the
door open for such a project to pull platform updates later. That promise covered code - the
kernel is a package with a stable name - and said nothing about `.ai/`, which is where an
agent learns how the platform works.

Left as one tree, `.ai/` makes an update impossible in practice. A project adds its own
decision as `adr/0024`; the platform ships its own `0024`. A project appends a row to the
lessons index; the platform appends one too. Every index becomes a merge conflict, and the
two kinds of knowledge - *how the platform works* and *what this project decided* - blur
until nobody can say which records an update is allowed to replace.

Three shapes were tried, in this order:

1. A `platform/` subdirectory inside each kind (`lessons/platform/`, `analysis/platform/`).
   Reverted: the boundary is repeated in every directory, and ADRs, specs and docs were left
   out of it, so there was no single thing an update could replace.
2. One `.ai/project/` directory for the project, everything else the platform's. One
   boundary, but the wrong way round: the project - which is what people work in every
   day - got the longer, unfamiliar paths, and an update still had to enumerate six
   directories to replace.
3. One `.ai/platform/` directory for the platform. Chosen.

## Decision
One boundary: **`.ai/platform/` ships with the platform; everything else under `.ai/` belongs
to the project.** `platform/` holds `adr/`, `specs/`, `docs/`, `skills/`, `lessons/` and
`analysis/`. The project side mirrors it - `.ai/adr/`, `.ai/specs/`, `.ai/lessons/`,
`.ai/analysis/`, each with its own index - and project ADRs are numbered from 0001
independently of the platform's.

Which side a new record belongs to is read from `"initialized"` in `.project.json`, never
judged: `false` is the platform's repository, `true` is a project. `make module` writes its
spec stub accordingly, with its row in the index.

## Consequences
- A platform update is "replace `.ai/platform/`". Nothing a project wrote is in the way, and
  nothing needs enumerating.
- A project works at the natural paths: its spec is `.ai/specs/…`, its decision `.ai/adr/…`.
- In the platform's repository the project side must stay empty apart from its indexes and
  the generated inventory - anything else would be handed to every new project as its own.
  `check-docs.sh` fails otherwise, and fails on any record missing from its index. Both are
  proved to fail in `make selftest`.
- A project never edits a platform record. To depart from one it writes its own ADR naming
  it, so the departure survives the next update instead of being overwritten by it.
- Every reference to a platform record got longer (`.ai/platform/docs/…`). Paid once.
- `.claude/skills` is a symlink and can point at one directory only; it points at the
  platform's skills. A project's own `.ai/skills/` is not picked up through it.
- `AGENTS.md` is still one shared file - `make module` adds Task Router rows to it - so it
  remains the one place an update must be merged by hand. Accepted for now.
- There is no update command yet. This record makes one possible; it does not provide it.
