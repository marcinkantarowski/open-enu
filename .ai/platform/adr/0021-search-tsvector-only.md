# ADR-0021 - Search is an interface with one Postgres implementation

**Status:** accepted · **Date:** 2026-09-10

## Context
The reference framework supports Meilisearch, pgvector, Qdrant and ChromaDB behind a search
abstraction. Excellent range, and every option is another service to run, configure, back up
and keep in sync.

## Decision
`SearchIndexerInterface` with exactly one built-in driver: a Postgres `tsvector` column with
a GIN index, maintained on write. Modules declare indexed fields in `search.php`.

The Postgres image is already `pgvector/pgvector:pg16`, so a vector driver is a class and a
migration if one is ever needed - no infrastructure change.

## Consequences
- Full-text search with zero extra services, adequate to well past the point where a project
  can afford a dedicated search cluster.
- No fuzzy matching or typo tolerance out of the box.
- Swapping in Meilisearch later means implementing one interface.
