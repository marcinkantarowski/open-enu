-- Runs once, on an empty data volume.
-- The application database is created by POSTGRES_DB; this adds what the
-- application needs beyond it.

-- Functional tests run against an isolated database so a failing test can never
-- touch development data (.ai/platform/PLAN.md §13). Created here rather than by the test
-- bootstrap so it exists before the first `make test` and needs no privileges.
SELECT 'CREATE DATABASE ' || current_database() || '_test'
WHERE NOT EXISTS (
  SELECT FROM pg_database WHERE datname = current_database() || '_test'
)\gexec

-- Trigram matching backs the tsvector search driver's fuzzy fallback (ADR-0021).
CREATE EXTENSION IF NOT EXISTS pg_trgm;
