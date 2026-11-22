# Platform services

The cross-cutting machinery every module gets from the kernel. **Read this before adding
any infrastructure to a module** - if a module seems to need caching, file storage,
encryption, search, background progress, feature flags or audit, the contract already
exists, and writing a local version is the most expensive mistake available here.

Each one is a contract with a working default. Several of those defaults are replaced by a
later module (Identity, Settings, Progress) *by decoration*, so code written today keeps
working with no call site changing.

| Need | Inject | Notes |
|---|---|---|
| Change state | `CommandBusInterface` | The only write path. See below |
| Cache something | `Cache\TenantCache` | Keys prefixed and tagged by tenant |
| Store a file | `Storage\StorageInterface` | Tenant-scoped keys, expiring URLs |
| Encrypt a column | `#[ORM\Column(type: 'encrypted_string')]` | Transparent; needs a `*_hash` sibling to search |
| Detect concurrent edits | `Contract\VersionedInterface` + `Doctrine\OptimisticLock` | Returns 409 with both versions |
| Announce something happened | extend `Event\DomainEvent` | Through the outbox |
| Push to the browser | `#[ClientBroadcast]` on the event | Opt-in, tenant-scoped topic |
| Report long-running work | `Progress\ProgressReporterInterface` | |
| Gate a feature | `Flags\FlagsInterface` or `#[Flag('x.y')]` | Declare it in a `FlagProviderInterface` too, or it can never be switched. Disabled ⇒ 404, not 403 |
| Full-text search | `search.php` in the module (+ `search.ts` in its frontend layer) | Postgres `tsvector`; see *Search* below |
| Set a tenant up | implement `Setup\TenantSetupInterface` | Auto-discovered, dependency-ordered |
| Answer a GDPR request | implement `Gdpr\GdprSubjectInterface` | Auto-discovered; **required** if you hold user data |
| Add fields to every log line | implement `Logging\LogContextProviderInterface` | |
| Influence response language | implement `I18n\LocalePreferenceProviderInterface` | |

---

## Writing: the command bus

Every state change is a command and a handler. Not a style preference - it is what makes
audit coverage structural instead of remembered.

```php
// Command/RenameProject.php - intent, as data
final readonly class RenameProject implements CommandInterface
{
    public function __construct(public string $id, public string $name) {}
    public function auditAction(): string { return 'example.project.renamed'; }
    public function auditSubjectId(): ?string { return $this->id; }
}

// Handler/RenameProjectHandler.php - the only place flush() is legal
#[AsMessageHandler]
final readonly class RenameProjectHandler
{
    public function __invoke(RenameProject $c): array
    {
        $item = $this->items->get($c->id) ?? throw new NotFoundHttpException();
        $this->snapshots->before($item->toArray());   // optional, but cheap
        $item->rename($c->name);
        $this->em->flush();
        $this->snapshots->after($item->toArray());
        return $item->toArray();
    }
}
```

Dispatching produces an audit entry **including when the handler throws** - an audit trail
that records only successes cannot answer "who tried?", which is most of what it is asked
after an incident.

`flush()`, `persist()` and `remove()` outside a `Handler/` fail `make arch`.

## Concurrent edits

```php
$this->lock->assertCurrent($item, $request, $payload, fn () => $item->toArray());
$result = $this->commands->dispatch(new RenameProject($id, $name));
```

The client sends the version it last saw in `If-Match: "3"` (or `version` in the body). A
mismatch is a `409` carrying **both** versions and the current record, so the UI can offer
reload / overwrite / diff instead of "your work is gone".

Entities need `#[ORM\Version]` **and** `VersionedInterface`. Having one without the other is
a build failure: the column without the interface means no protection, the interface without
the column means every check silently passes.

## Encryption

```php
#[ORM\Column(type: 'encrypted_string', nullable: true)]
private ?string $internalNote = null;
```

Ciphertext at rest, plaintext in PHP, per-tenant key derived from `APP_ENCRYPTION_KEY`.

The cost: **an encrypted column cannot be searched or sorted in SQL.** For equality lookups
add a sibling `*_hash` column written with `Encryptor::hashForLookup()` - that is how login
finds a user by an encrypted email address.

## Open-ended data

There is no runtime custom-fields layer, deliberately (ADR-0013). When an entity genuinely
needs per-tenant open-ended data, use a JSONB column with a GIN index and document it in
`MODULE.md`:

```php
#[ORM\Column(type: Types::JSON, options: ['jsonb' => true])]
private array $attributes = [];
```

## Search

A module makes its records findable by declaring them in `search.php` - the fields to index
and the permission that may see them (an excerpt is content). The kernel keeps the index in
step on every write; nothing calls `index()` by hand. `GET /api/search?q=` answers across every
module, for the tenant and the units in scope.

The query is built for a box that answers while someone types:

- **every word is a prefix, all must match, in any order** - "kowal jan" finds "Jan Kowalski";
- **accents do not count** - "zajac" finds "Zając": the `open_enu_search` text search
  configuration (kernel migration `Version20200101000002`) strips them from documents and
  queries, while the stored text and the excerpt keep them;
- an e-mail or a "12-345" stays one word; characters `to_tsquery` would read as operators
  are dropped, so a stray `(` is a typo, not a 500;
- a short record (up to 160 characters) comes back whole with the matches in `<b>`; a long
  one is cut to the part around them.

In the browser, the shell's search box (`/` or Ctrl K) shows a hit only where the module's
frontend layer says which page opens it - `search.ts`, see `frontend-conventions.md` →
*Search*. `make module-check` fails on an entity in `search.php` with no target there.

Never index an encrypted column: the index stores plaintext and hands back excerpts of it.

## Tenant setup

```php
final readonly class BillingSetup implements TenantSetupInterface
{
    public function onTenantCreated(string $tenantId): void { /* defaults it cannot run without */ }
    public function seedExamples(string $tenantId): void   { /* demo data */ }
}
```

Discovered automatically, run in module dependency order. **Both must be idempotent** -
provisioning gets retried, and a second run that duplicates its defaults turns one bad
signup into permanent bad data. Run them with `make seed`.

## GDPR

If your module stores anything linked to a person, implement `GdprSubjectInterface`. This is
not optional politeness: an arch test fails the build for any entity with a `user_id` whose
module does not, because an export that silently misses a module is a wrong answer to a
legal request.

```bash
make console CMD="app:gdpr export user-123"
make console CMD="app:gdpr erase user-123 --force"
```

Erasure runs in **reverse** dependency order - the mirror of setup - so a module holding
references lets go before the module it references disappears.

---

## What these are not

They are not extension points. The tenant filter, the audience check, the audit middleware
and the outbox are the guarantees the system makes; there is no hook to decorate them away.
See [`extension-surfaces.md`](extension-surfaces.md) for what *is* extendable.
