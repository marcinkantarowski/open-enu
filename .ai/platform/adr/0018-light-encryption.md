# ADR-0018 - Field encryption with derived per-tenant keys, not a KMS

**Status:** accepted · **Date:** 2026-09-10

## Context
The reference framework encrypts tenant fields with per-tenant DEKs held in HashiCorp Vault. Correct
for enterprise, and it makes Vault a prerequisite for booting - which contradicts this
repository's rule that it must start with zero external services.

## Decision
`#[Encrypted]` on a property selects a Doctrine type doing AES-256-GCM. The per-tenant data
key is **derived** by HKDF from `APP_ENCRYPTION_KEY` and the tenant UUID, so no key material
is stored per tenant. Lookups on encrypted fields use a sibling `*_hash` column (SHA-256 of
the normalized value) - `User.email` is ciphertext, login queries `email_hash`.

`KeyProviderInterface` exists from Phase 3 with one implementation, so a KMS-backed provider
is a class, not a refactor. `make encryption-rotate` re-encrypts under a new master key.

## Consequences
- Boots with nothing external; encryption is on by default rather than aspirational.
- `APP_ENCRYPTION_KEY` is now catastrophic to lose - it ships in the encrypted backup bundle
  and the operator is told to store the age private key off-server.
- Encrypted columns cannot be searched or sorted in SQL; `*_hash` covers equality only.
