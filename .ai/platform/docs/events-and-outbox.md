# Events and background work

Three transports, and which one a message takes is decided by what it *is*, not by where it
was dispatched from.

| Kind | Marker | Transport | Why |
|---|---|---|---|
| Something happened | extend `Event\DomainEvent` | `outbox` (Doctrine) | commits in the same transaction as the write |
| Do this later | implement `Message\JobInterface` | `jobs` (Redis) | no transactional relationship to the write |
| Anything that failed | - | `failed` (Doctrine) | dead letters stay readable and replayable |

One routing line covers every event and every job any module will ever define, because
Messenger matches parent classes:

```yaml
OpenEnu\Kernel\Event\DomainEvent: outbox
OpenEnu\Kernel\Message\JobInterface: jobs
```

---

## Events

```php
// Event/ProjectCreated.php
#[ClientBroadcast]                       // optional: also push to the open browser tab
final readonly class ProjectCreated extends DomainEvent
{
    public function __construct(public string $id, public string $name) {}
}
```

```php
$this->events->dispatch(new ProjectCreated((string) $project->id(), $project->name()));
```

**The outbox is the point.** A Doctrine transport means the event row is written inside the
same transaction as the change it announces, so there is no window where the write
committed and the announcement was lost - or the reverse ([ADR-0009](../adr/0009-events-via-outbox.md)).

It also removes a sharp edge: on a synchronous bus a message with no handler is an *error*,
so announcing something nobody happens to be listening for yet would fail the request that
announced it. An event is a statement of fact; whether anyone cares is not the publisher's
problem.

### Listening

```php
#[AsMessageHandler]
final readonly class NotifyOnProjectCreated
{
    public function __invoke(ProjectCreated $event): void { /* … */ }
}
```

A listener runs in the worker, with the tenant restored from the message's `TenantStamp`.
Nothing about the dispatching request survives except what the event carries - so an event
carries **ids and names, never entities**.

### Reaching the browser

`#[ClientBroadcast]` publishes the event to `/tenants/{tid}/events` on Mercure. Opt-in, and
tenant-scoped: the subscriber token is restricted to the caller's own topics, because a
`["*"]` subscribe claim would let any authenticated user receive every tenant's updates -
a cross-tenant leak no query filter can see, because no query runs.

`MODULE.md` must list every `#[ClientBroadcast]` event the module defines; `make docs-check`
enforces that.

---

## Jobs

```php
final readonly class ArchiveProjectsJob implements JobInterface
{
    public function __construct(public array $ids, public string $progressId) {}
}
```

Redis rather than Doctrine, for the opposite reason: a job has no transactional
relationship to the write that asked for it - it is dispatched by a handler *once that
write has committed*. Long work reports through `ProgressReporterInterface`, which is what
the progress bar polls and what the SSE stream pushes.

Mail takes the same queue: an SMTP timeout must not fail a registration that has already
been committed.

---

## Failure

Failed messages land in the Doctrine `failed` transport, where they can be read as rows and
replayed:

```bash
make console CMD="messenger:failed:show"
make console CMD="messenger:failed:retry --force"
```

Retries use exponential backoff. **Recording why something failed cannot happen in the
handler that failed** - the throw rolls its transaction back and takes the record with it.
Messenger's `WorkerMessageFailedEvent` fires after the rollback; that is where an attempt
log belongs. The Webhook module does exactly this, and the lesson explaining why is
[`a-handler-cannot-record-its-own-failure`](../lessons/a-handler-cannot-record-its-own-failure.md).

---

## Where to look

- `backend/src/Module/Example/` - an event, a job, a progress bar and a handler, all small
- `backend/src/Module/Webhook/` - the same machinery carrying events out of the system
- `GET /api/manager/workers` - queue depth and dead-letter count, which is the first thing
  to look at when something "didn't happen"
