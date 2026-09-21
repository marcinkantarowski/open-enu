<?php

declare(strict_types=1);

namespace App\Module\Attachment\Entity;

use App\Module\Attachment\Repository\AttachmentRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use OpenEnu\Kernel\Contract\TenantScopedInterface;
use Symfony\Component\Uid\Uuid;

/**
 * A stored file, and what it belongs to.
 *
 * The owner is recorded as a type/id pair rather than a relation: an attachment
 * must be able to belong to any module's record, and a foreign key to each of
 * them would be exactly the cross-module coupling the boundaries forbid
 * (ADR-0002).
 *
 * The original filename is kept HERE, not used as the storage key. The key is a
 * generated ULID, so a user-supplied name can never become a path.
 */
#[ORM\Entity(repositoryClass: AttachmentRepository::class)]
#[ORM\Table(name: 'attachment')]
#[ORM\Index(columns: ['tenant_id', 'owner_type', 'owner_id'], name: 'idx_attachment_owner')]
class Attachment implements TenantScopedInterface
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\Column(name: 'tenant_id', type: 'uuid')]
    private Uuid $tenantId;

    /** Storage key, e.g. `tenants/{tid}/attachment/01JC….pdf`. Never a path from user input. */
    #[ORM\Column(type: Types::STRING, length: 255)]
    private string $storageKey;

    #[ORM\Column(type: Types::STRING, length: 255)]
    private string $filename;

    #[ORM\Column(type: Types::STRING, length: 127)]
    private string $contentType;

    #[ORM\Column(type: Types::BIGINT)]
    private string $size;

    /** What this file is attached to. Null while it is still an orphan. */
    #[ORM\Column(type: Types::STRING, length: 64, nullable: true)]
    private ?string $ownerType = null;

    #[ORM\Column(type: Types::STRING, length: 64, nullable: true)]
    private ?string $ownerId = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        string $tenantId,
        string $storageKey,
        string $filename,
        string $contentType,
        int $size,
    ) {
        $this->id = Uuid::v7();
        $this->tenantId = Uuid::fromString($tenantId);
        $this->storageKey = $storageKey;
        $this->filename = $filename;
        $this->contentType = $contentType;
        $this->size = (string) $size;
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

    public function storageKey(): string
    {
        return $this->storageKey;
    }

    public function filename(): string
    {
        return $this->filename;
    }

    public function contentType(): string
    {
        return $this->contentType;
    }

    public function size(): int
    {
        return (int) $this->size;
    }

    public function attachTo(string $ownerType, string $ownerId): void
    {
        $this->ownerType = $ownerType;
        $this->ownerId = $ownerId;
    }

    public function isOrphan(): bool
    {
        return $this->ownerId === null;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => (string) $this->id,
            'filename' => $this->filename,
            'contentType' => $this->contentType,
            'size' => $this->size(),
            'ownerType' => $this->ownerType,
            'ownerId' => $this->ownerId,
            'createdAt' => $this->createdAt->format(\DATE_ATOM),
        ];
    }
}
