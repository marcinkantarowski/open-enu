<?php

declare(strict_types=1);

namespace App\Module\Audit\Entity;

use App\Module\Audit\Repository\AuditEntryRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * One recorded change. Append-only.
 *
 * Deliberately NOT TenantScopedInterface. Two reasons, and they pull the same
 * way: entries are written for actions that have no tenant (registration,
 * platform operations), and the filter would hide exactly the cross-tenant
 * entries an operator investigating an incident needs. Access is restricted by
 * permission instead, which is an explicit decision rather than an invisible
 * default.
 *
 * There is no update path and no delete path. An audit trail that can be edited
 * is not evidence of anything.
 */
#[ORM\Entity(repositoryClass: AuditEntryRepository::class)]
#[ORM\Table(name: 'audit_entry')]
#[ORM\Index(columns: ['tenant_id', 'recorded_at'], name: 'idx_audit_tenant_time')]
#[ORM\Index(columns: ['action'], name: 'idx_audit_action')]
#[ORM\Index(columns: ['actor_id'], name: 'idx_audit_actor')]
#[ORM\Index(columns: ['subject_id'], name: 'idx_audit_subject')]
class AuditEntry
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\Column(type: Types::STRING, length: 100)]
    private string $action;

    #[ORM\Column(name: 'tenant_id', type: Types::STRING, length: 64, nullable: true)]
    private ?string $tenantId;

    #[ORM\Column(name: 'actor_id', type: Types::STRING, length: 64, nullable: true)]
    private ?string $actorId;

    /** The operator behind an impersonated session (ADR-0008). */
    #[ORM\Column(name: 'on_behalf_of_id', type: Types::STRING, length: 64, nullable: true)]
    private ?string $onBehalfOfId;

    #[ORM\Column(name: 'subject_id', type: Types::STRING, length: 64, nullable: true)]
    private ?string $subjectId;

    #[ORM\Column(name: 'request_id', type: Types::STRING, length: 64, nullable: true)]
    private ?string $requestId;

    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $succeeded;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $failureReason;

    /** @var array<string, mixed>|null */
    #[ORM\Column(type: Types::JSON, nullable: true, options: ['jsonb' => true])]
    private ?array $before;

    /** @var array<string, mixed>|null */
    #[ORM\Column(type: Types::JSON, nullable: true, options: ['jsonb' => true])]
    private ?array $after;

    /** @var array<string, mixed> */
    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true])]
    private array $context;

    #[ORM\Column(name: 'recorded_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $recordedAt;

    /**
     * @param array<string, mixed>|null $before
     * @param array<string, mixed>|null $after
     * @param array<string, mixed>      $context
     */
    public function __construct(
        string $action,
        ?string $tenantId,
        ?string $actorId,
        ?string $onBehalfOfId,
        ?string $subjectId,
        ?string $requestId,
        bool $succeeded,
        ?string $failureReason,
        ?array $before,
        ?array $after,
        array $context,
        \DateTimeImmutable $recordedAt,
    ) {
        $this->id = Uuid::v7();
        $this->action = $action;
        $this->tenantId = $tenantId;
        $this->actorId = $actorId;
        $this->onBehalfOfId = $onBehalfOfId;
        $this->subjectId = $subjectId;
        $this->requestId = $requestId;
        $this->succeeded = $succeeded;
        $this->failureReason = $failureReason;
        $this->before = $before;
        $this->after = $after;
        $this->context = $context;
        $this->recordedAt = $recordedAt;
    }

    public function id(): Uuid
    {
        return $this->id;
    }

    public function action(): string
    {
        return $this->action;
    }

    public function actorId(): ?string
    {
        return $this->actorId;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => (string) $this->id,
            'action' => $this->action,
            'tenantId' => $this->tenantId,
            'actorId' => $this->actorId,
            'onBehalfOfId' => $this->onBehalfOfId,
            'subjectId' => $this->subjectId,
            'requestId' => $this->requestId,
            'succeeded' => $this->succeeded,
            'failureReason' => $this->failureReason,
            'before' => $this->before,
            'after' => $this->after,
            'context' => $this->context,
            'recordedAt' => $this->recordedAt->format(\DATE_ATOM),
        ];
    }
}
