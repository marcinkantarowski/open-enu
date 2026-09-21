# Progress

Owns long-running operations: what is running, how far it has got, and how it ended.

## Owns

- `ProgressJob` - kind, total, done, status, result.
- `PersistentProgressReporter` - decorates the kernel's log-only default, so a handler
  written in Phase 2b against `ProgressReporterInterface` now produces durable, queryable
  jobs without changing a line.

## Public contracts

_(none - the kernel's `ProgressReporterInterface` is the contract; this module implements it.)_

## Events

_(none yet.)_

## Permissions

- `progress.view`

## Notes for agents

- Writes are `#[InfrastructureWrite]`, outside the command bus on purpose: progress is
  metadata *about* work, not the work. Auditing every increment would bury the audit trail,
  and a rolled-back job should still show why it stopped.
- `advance()` is clamped. A handler that miscounts should show 100%, not 143%.
