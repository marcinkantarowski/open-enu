# ADR-0001 - Modular backend over flat layers

**Status:** accepted · **Date:** 2026-09-10

## Context
The production project this stack was taken from uses flat Symfony layers (`src/Controller`, `src/Service`,
`src/Entity`, …). It works, but a feature is spread across ten top-level folders, and an
agent adding the tenth feature must hold all ten in context to keep them coherent.

## Decision
Every feature is a self-contained module: `backend/src/Module/<Name>/` holding its own
controllers, entities, services, events, ACL, migrations, fixtures, tests and `MODULE.md`.
Modules are auto-discovered - no central file is edited to add one.

## Consequences
- A feature's blast radius is one directory; an agent can hold it whole.
- Module boundaries need enforcement or they erode: see ADR-0002's PHPStan rules.
- Cross-module access costs more by design (contracts and events, never direct imports).
