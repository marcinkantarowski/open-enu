<?php

declare(strict_types=1);

namespace App\Module\Example\Entity;

use App\Module\Example\Repository\ProjectRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use OpenEnu\Kernel\Contract\TenantScopedInterface;
use OpenEnu\Kernel\Contract\VersionedInterface;
use OpenEnu\Kernel\Doctrine\Type\EncryptedTenantStringType;
use Symfony\Component\Uid\Uuid;

/**
 * The entity to copy.
 *
 * Every cross-cutting concern this platform has is one line here, which is the
 * point of the module:
 *
 *   TenantScopedInterface    every query gains `tenant_id = ?`, and with no
 *                            tenant in context returns NOTHING (ADR-0004)
 *   VersionedInterface       a concurrent edit is a 409 carrying both versions,
 *                            not a silent overwrite (ADR-0019)
 *   encrypted_tenant_string  ciphertext at rest, plaintext in PHP, keyed by this
 *                            row's tenant (ADR-0018)
 *   attributes JSONB         open-ended per-record data - the documented
 *                            alternative to a custom-fields subsystem (ADR-0013)
 *
 * Attachments are deliberately NOT a column here. An upload carries
 * `ownerType: 'example_project'` and this row's id, so the association lives on
 * the Attachment side and neither module knows the other's schema.
 */
#[ORM\Entity(repositoryClass: ProjectRepository::class)]
#[ORM\Table(name: 'example_project')]
#[ORM\Index(columns: ['tenant_id'], name: 'idx_example_project_tenant')]
class Project implements VersionedInterface, TenantScopedInterface
{
    public const string STATUS_ACTIVE = 'active';
    public const string STATUS_ARCHIVED = 'archived';

    /**
     * Named explicitly rather than left to the naming strategy: `ScopeFilter`
     * looks for `tenant_id`, and inferring it would make the filter's behaviour
     * depend on a setting far from here.
     *
     * A plain indexed uuid, not a foreign key - cross-module associations are a
     * build failure (ADR-0002).
     */
    #[ORM\Column(name: 'tenant_id', type: 'uuid')]
    private Uuid $tenantId;

    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\Column(type: Types::STRING, length: 200)]
    private string $name;

    /**
     * Ciphertext at rest. Valid here because Project IS tenant-scoped, so the
     * ambient scope is always this row's tenant; on an unscoped entity this type
     * is a trap and `encrypted_string` is the one to use.
     *
     * Never queryable: add a `*_hash` sibling if you need to look one up.
     */
    #[ORM\Column(type: EncryptedTenantStringType::NAME, nullable: true)]
    private ?string $clientReference = null;

    /**
     * Indexed for search, together with the name (`search.php`).
     *
     * Plaintext on purpose: `clientReference` above is NOT indexed and must
     * never be, because an index stores the text and returns excerpts of it -
     * indexing an encrypted column would decrypt it into a table with no access
     * control of its own.
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    /** @var array<string, mixed> */
    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true])]
    private array $attributes = [];

    #[ORM\Column(type: Types::STRING, length: 16)]
    private string $status = self::STATUS_ACTIVE;

    #[ORM\Version]
    #[ORM\Column(type: Types::INTEGER)]
    private int $version = 1;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    /**
     * @param ?string $id a fixed id, for fixtures and imports
     *
     * Injectable rather than always generated because determinism is a stated
     * property here: fixtures use fixed uuids so a functional test's output is
     * byte-reproducible and can be asserted on rather than guessed at
     * (.ai/platform/PLAN.md §12.1).
     */
    public function __construct(string $name, string $tenantId, ?string $id = null)
    {
        $this->id = $id === null ? Uuid::v7() : Uuid::fromString($id);
        $this->name = $name;
        $this->tenantId = Uuid::fromString($tenantId);
        $this->createdAt = new \DateTimeImmutable();
    }

    public function id(): Uuid
    {
        return $this->id;
    }

    public function tenantId(): string
    {
        return (string) $this->tenantId;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function rename(string $name): void
    {
        $this->name = $name;
    }

    public function setDescription(?string $description): void
    {
        $this->description = $description;
    }

    public function clientReference(): ?string
    {
        return $this->clientReference;
    }

    public function setClientReference(?string $reference): void
    {
        $this->clientReference = $reference;
    }

    /** @param array<string, mixed> $attributes */
    public function setAttributes(array $attributes): void
    {
        $this->attributes = $attributes;
    }

    public function status(): string
    {
        return $this->status;
    }

    public function isArchived(): bool
    {
        return $this->status === self::STATUS_ARCHIVED;
    }

    public function archive(): void
    {
        $this->status = self::STATUS_ARCHIVED;
    }

    public function version(): int
    {
        return $this->version;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => (string) $this->id,
            'name' => $this->name,
            'description' => $this->description,
            // The encrypted column is deliberately absent from the list shape;
            // `show` adds it. What leaves the server is a decision per endpoint.
            'attributes' => $this->attributes,
            'status' => $this->status,
            'version' => $this->version,
            'createdAt' => $this->createdAt->format(\DATE_ATOM),
        ];
    }
}
