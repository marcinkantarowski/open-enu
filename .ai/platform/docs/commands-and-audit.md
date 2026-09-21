# Commands and audit

Every state change is a command and a handler. `flush()` outside a `Handler/` directory is
a build failure - which is what makes audit coverage structural instead of remembered.
See [ADR-0017](../adr/0017-command-bus-for-writes.md).

---

## The shape

```php
// Command/RenameProject.php - intent, as data
final readonly class RenameProject implements CommandInterface
{
    public function __construct(public string $id, public string $name) {}

    /** Stable, dotted: this is what the trail is queried by, so it is a contract. */
    public function auditAction(): string { return 'example.project.renamed'; }

    public function auditSubjectId(): ?string { return $this->id; }
}
```

```php
// Handler/RenameProjectHandler.php - the work
#[AsMessageHandler]
final readonly class RenameProjectHandler
{
    public function __construct(
        private ProjectRepository $projects,
        private EntityManagerInterface $em,
        private SnapshotCollector $snapshots,
    ) {}

    public function __invoke(RenameProject $command): array
    {
        $project = $this->projects->get($command->id) ?? throw new NotFoundHttpException();

        // Before the change, while the old state is still loaded.
        $this->snapshots->before($project->toArray());
        $project->rename($command->name);
        $this->em->flush();
        $this->snapshots->after($project->toArray());

        return $project->toArray();
    }
}
```

The controller validates, dispatches and serialises. Nothing else.

```php
return new JsonResponse($this->commands->dispatch(new RenameProject($id, $name)));
```

The bus is Messenger, synchronous, wrapped in `doctrine_transaction`. A handler is
therefore atomic - and, as a direct consequence, **a write followed by a throw did not
happen**. Anything that must survive a failure has to be written outside the failing
transaction; see
[`a-handler-cannot-record-its-own-failure`](../lessons/a-handler-cannot-record-its-own-failure.md).

---

## What lands in the trail

| Field | From |
|---|---|
| `action` | `$command->auditAction()` |
| `subjectId` | `$command->auditSubjectId()` |
| `actorId` | the authenticated user, operator or key |
| `onBehalfOfId` | set when the session is impersonated (ADR-0008) |
| `tenantId` | the ambient scope - see below |
| `requestId` | the correlation id, the same one in the logs |
| `before` / `after` | whatever the handler pushed into `SnapshotCollector` |
| `succeeded` / `failureReason` | a refused write is recorded too |

Snapshots are **opt-in**, and deliberately so: a handler serialising an entity is the only
code that knows which fields are worth keeping and which are noise or secrets. A command
with no snapshot still produces an entry; it just cannot answer "what did it look like
before?".

### Commands that act on a tenant from outside it

An operator has no tenant in scope - the manager realm does not have one, by design. A
command that nonetheless acts on a specific tenant says so:

```php
final readonly class SuspendTenant implements CommandInterface, TenantTargetedInterface
{
    public function auditTenantId(): string { return $this->tenantId; }
}
```

Without it, the most consequential entries in the system - suspensions, impersonations,
kill switches flipped during an incident - are written with no tenant at all and vanish
from the only query anybody runs: *what happened to this customer?*

---

## The documented exceptions

Some writes legitimately skip the bus: issuing and rotating session tokens, a `lastUsedAt`
stamp, a progress counter, read state on your own notification feed. Auditing those buries
the trail in noise that hides the entries worth reading.

They are marked, with a reason, and therefore greppable:

```php
#[InfrastructureWrite(reason: 'read state on your own feed; auditing a glance would bury the trail')]
public function markRead(string $userId, string $notificationId): void
```

"Show me every write that skips the audit trail" is one search. If the reason is *this
would be tedious as a command*, it is not one.

---

## Reading it

| Audience | Endpoint | Scope |
|---|---|---|
| A tenant | `GET /api/audit` | its own rows, narrowed in the controller |
| An operator | `GET /api/manager/audit?tenantId=…` | any tenant, via `runUnscoped()` |

The entity itself is deliberately **not** `TenantScopedInterface`: an operator must be able
to read across tenants, so the restriction lives in the tenant-facing controller where it
can be seen.

The trail is append-only and ordered newest-first, with the id as a tie-break - two entries
written in the same second otherwise come back in whatever order Postgres feels like, and a
page whose order changes between two reads is not a trail.

There is no undo, on purpose: [ADR-0015](../adr/0015-no-undo.md). The snapshots are for
answering questions, not for replaying history backwards.

---

## Where to look

- `backend/src/Module/Example/Handler/` - the shape to copy
- `backend/kernel/src/Command/MessengerCommandBus.php` - where an entry is built
- `backend/src/Module/Audit/Tests/Functional/AuditApiTest.php` - what the trail promises
