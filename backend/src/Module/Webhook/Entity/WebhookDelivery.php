<?php

declare(strict_types=1);

namespace App\Module\Webhook\Entity;

use App\Module\Webhook\Repository\WebhookDeliveryRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use OpenEnu\Kernel\Contract\TenantScopedInterface;
use Symfony\Component\Uid\Uuid;

/**
 * One attempt-history for one event going to one endpoint.
 *
 * The log is the product here, not a debugging aid: "did you send it?" is the
 * only question anyone asks about webhooks, and answering it after the fact
 * needs the payload, the response code and the attempt count kept.
 */
#[ORM\Entity(repositoryClass: WebhookDeliveryRepository::class)]
#[ORM\Table(name: 'webhook_delivery')]
#[ORM\Index(columns: ['tenant_id'], name: 'idx_webhook_delivery_tenant')]
#[ORM\Index(columns: ['endpoint_id'], name: 'idx_webhook_delivery_endpoint')]
class WebhookDelivery implements TenantScopedInterface
{
    public const string PENDING = 'pending';
    public const string DELIVERED = 'delivered';
    public const string FAILED = 'failed';

    /** Matches the `jobs` transport's retry_strategy. Past this, a person looks. */
    public const int MAX_ATTEMPTS = 5;

    #[ORM\Column(name: 'tenant_id', type: 'uuid')]
    private Uuid $tenantId;

    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    /** A plain uuid, not an association: cross-module rules apply inside one too. */
    #[ORM\Column(name: 'endpoint_id', type: 'uuid')]
    private Uuid $endpointId;

    #[ORM\Column(type: Types::STRING, length: 120)]
    private string $eventName;

    /** @var array<string, mixed> */
    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true])]
    private array $payload;

    #[ORM\Column(type: Types::STRING, length: 16)]
    private string $status = self::PENDING;

    #[ORM\Column(type: Types::INTEGER)]
    private int $attempts = 0;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $responseCode = null;

    /** Truncated on write: a receiver returning a megabyte of HTML must not fill the table. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $responseBody = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastAttemptAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    /** @param array<string, mixed> $payload */
    public function __construct(string $tenantId, string $endpointId, string $eventName, array $payload)
    {
        $this->id = Uuid::v7();
        $this->tenantId = Uuid::fromString($tenantId);
        $this->endpointId = Uuid::fromString($endpointId);
        $this->eventName = $eventName;
        $this->payload = $payload;
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

    public function endpointId(): string
    {
        return (string) $this->endpointId;
    }

    public function eventName(): string
    {
        return $this->eventName;
    }

    /** @return array<string, mixed> */
    public function payload(): array
    {
        return $this->payload;
    }

    public function attempts(): int
    {
        return $this->attempts;
    }

    public function succeeded(int $code): void
    {
        ++$this->attempts;
        $this->status = self::DELIVERED;
        $this->responseCode = $code;
        $this->responseBody = null;
        $this->lastAttemptAt = new \DateTimeImmutable();
    }

    public function failed(?int $code, string $reason): void
    {
        ++$this->attempts;
        // `pending` until the retries are spent: a delivery still queued is not
        // a failure, and showing it as one sends people chasing a non-problem.
        $this->status = $this->attempts >= self::MAX_ATTEMPTS ? self::FAILED : self::PENDING;
        $this->responseCode = $code;
        $this->responseBody = mb_substr($reason, 0, 2000);
        $this->lastAttemptAt = new \DateTimeImmutable();
    }

    /** Redelivery starts the history again rather than editing it. */
    public function reset(): void
    {
        $this->status = self::PENDING;
        $this->attempts = 0;
        $this->responseCode = null;
        $this->responseBody = null;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => (string) $this->id,
            'endpointId' => (string) $this->endpointId,
            'event' => $this->eventName,
            'status' => $this->status,
            'attempts' => $this->attempts,
            'responseCode' => $this->responseCode,
            'responseBody' => $this->responseBody,
            'lastAttemptAt' => $this->lastAttemptAt?->format(\DATE_ATOM),
            'createdAt' => $this->createdAt->format(\DATE_ATOM),
        ];
    }
}
