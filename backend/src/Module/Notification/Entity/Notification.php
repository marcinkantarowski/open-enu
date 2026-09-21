<?php

declare(strict_types=1);

namespace App\Module\Notification\Entity;

use App\Module\Notification\Repository\NotificationRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use OpenEnu\Kernel\Contract\TenantScopedInterface;
use Symfony\Component\Uid\Uuid;

/**
 * One thing one person should know about.
 *
 * Stores the type and its arguments, never a rendered sentence: the recipient's
 * language is a property of the reader, not of the event, and a feed written in
 * English at write time can never be read in Polish (ADR-0020).
 */
#[ORM\Entity(repositoryClass: NotificationRepository::class)]
#[ORM\Table(name: 'notification')]
#[ORM\Index(columns: ['tenant_id'], name: 'idx_notification_tenant')]
#[ORM\Index(columns: ['user_id'], name: 'idx_notification_user')]
class Notification implements TenantScopedInterface
{
    #[ORM\Column(name: 'tenant_id', type: 'uuid')]
    private Uuid $tenantId;

    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    /** A plain uuid: no ORM association across modules (ADR-0002). */
    #[ORM\Column(name: 'user_id', type: 'uuid')]
    private Uuid $userId;

    #[ORM\Column(type: Types::STRING, length: 120)]
    private string $type;

    /** @var array<string, scalar|null> translation arguments, not prose */
    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true])]
    private array $context;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $readAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    /** @param array<string, scalar|null> $context */
    public function __construct(string $tenantId, string $userId, string $type, array $context = [])
    {
        $this->id = Uuid::v7();
        $this->tenantId = Uuid::fromString($tenantId);
        $this->userId = Uuid::fromString($userId);
        $this->type = $type;
        $this->context = $context;
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

    public function type(): string
    {
        return $this->type;
    }

    /** @return array<string, scalar|null> */
    public function context(): array
    {
        return $this->context;
    }

    public function isRead(): bool
    {
        return $this->readAt !== null;
    }

    public function markRead(): void
    {
        // Idempotent: two tabs marking the same item must not move the timestamp
        // and make "when did I read this?" a lie.
        $this->readAt ??= new \DateTimeImmutable();
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => (string) $this->id,
            'type' => $this->type,
            'context' => $this->context,
            'read' => $this->readAt !== null,
            'readAt' => $this->readAt?->format(\DATE_ATOM),
            'createdAt' => $this->createdAt->format(\DATE_ATOM),
        ];
    }
}
