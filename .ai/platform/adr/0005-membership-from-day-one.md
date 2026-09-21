# ADR-0005 - `Membership(user, tenant, role)` from day one

**Status:** accepted · **Date:** 2026-09-10

## Context
One-tenant-per-user is simpler and is what a `tenant_id` on `users` gives you. Every real
SaaS then needs a user in two tenants within a year - agencies, consultants, your own
staff - and retrofitting membership is a migration that touches every query and every token.

## Decision
A `Membership` join entity from the start. The JWT carries the **current** tenant (`tid`)
and the role for that membership; `POST /auth/switch-tenant` verifies and re-issues. Roles
are per-membership, not per-user.

## Consequences
- One extra join and a tenant switcher in the UI, forever.
- The expensive migration never happens.
- "Current tenant" becomes a token concern, which the fail-closed filter already assumes.
