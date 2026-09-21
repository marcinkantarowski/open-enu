# Audit

Owns the durable record of every change: who, what, when, and what it looked like before.

## Owns

- `AuditEntry` - append-only. **Deliberately not tenant-scoped**: entries are written for
  actions that have no tenant (registration, platform operations), and the filter would hide
  exactly the cross-tenant entries an operator investigating an incident needs. Access is
  restricted by permission instead, which is an explicit decision rather than an invisible
  default.
- `PersistentAuditLogger` - decorates the kernel's log-only default, so every command
  recorded since Phase 2b now lands in a queryable table without a single call site changing.

## Public contracts

- `Contract\AuditReaderInterface` - reading the trail, for the operator console. Returns
  rows, not entities: an entry is append-only, so handing out the object would offer a
  setter that must never be called.

Writing goes the other way: the kernel's `AuditLoggerInterface` is the contract, and this
module implements it.

## Events

_(none.)_

## Permissions

- `audit.view`

## Notes for agents

- Writes use DBAL, not the ORM, and that is load-bearing: the command bus records a FAILED
  command after its transaction has rolled back, and an ORM write there would be discarded
  with it - losing exactly the entries most worth having.
- `record()` never throws. Failing to record a change must not undo the change.
- GDPR erasure **anonymises**; it does not delete. Destroying the record of what someone did
  is not the same as forgetting who they were.
