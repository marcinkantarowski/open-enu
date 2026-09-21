<?php

declare(strict_types=1);

namespace App\Module\Progress\Entity;

use App\Module\Progress\Repository\ProgressJobRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use OpenEnu\Kernel\Contract\TenantScopedInterface;
use Symfony\Component\Uid\Uuid;

/**
 * A unit of work that outlives its request.
 *
 * Every product grows one - an import, a bulk update, a report. Without a shared
 * shape, each invents its own polling and its own UI, and none of them can be
 * resumed or inspected after the tab is closed.
 */
#[ORM\Entity(repositoryClass: ProgressJobRepository::class)]
#[ORM\Table(name: 'progress_job')]
#[ORM\Index(columns: ['tenant_id', 'status'], name: 'idx_progress_tenant_status')]
class ProgressJob implements TenantScopedInterface
{
    public const string STATUS_RUNNING = 'running';
    public const string STATUS_DONE = 'done';
    public const string STATUS_FAILED = 'failed';

    #[ORM\Id]
    #[ORM\Column(type: Types::STRING, length: 32)]
    private string $id;

    #[ORM\Column(name: 'tenant_id', type: 'uuid')]
    private Uuid $tenantId;

    #[ORM\Column(type: Types::STRING, length: 64)]
    private string $kind;

    #[ORM\Column(type: Types::STRING, length: 200, nullable: true)]
    private ?string $label = null;

    #[ORM\Column(type: Types::INTEGER)]
    private int $total;

    #[ORM\Column(type: Types::INTEGER)]
    private int $done = 0;

    #[ORM\Column(type: Types::STRING, length: 16)]
    private string $status = self::STATUS_RUNNING;

    /** @var array<string, mixed> */
    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true])]
    private array $result = [];

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $failureReason = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $startedAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $finishedAt = null;

    public function __construct(string $id, string $tenantId, string $kind, int $total, ?string $label)
    {
        $this->id = $id;
        $this->tenantId = Uuid::fromString($tenantId);
        $this->kind = $kind;
        $this->total = max(0, $total);
        $this->label = $label;
        $this->startedAt = new \DateTimeImmutable();
    }

    public function id(): string
    {
        return $this->id;
    }

    public function tenantId(): string
    {
        return (string) $this->tenantId;
    }

    public function advance(int $by, ?string $label): void
    {
        // Clamped: a handler that miscounts should show 100%, not 143%.
        $this->done = min($this->total, $this->done + max(0, $by));

        if ($label !== null) {
            $this->label = $label;
        }
    }

    /** @param array<string, mixed> $result */
    public function finish(array $result): void
    {
        $this->status = self::STATUS_DONE;
        $this->done = $this->total;
        $this->result = $result;
        $this->finishedAt = new \DateTimeImmutable();
    }

    public function fail(string $reason): void
    {
        $this->status = self::STATUS_FAILED;
        $this->failureReason = $reason;
        $this->finishedAt = new \DateTimeImmutable();
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'kind' => $this->kind,
            'label' => $this->label,
            'total' => $this->total,
            'done' => $this->done,
            'percent' => $this->total > 0 ? (int) round($this->done / $this->total * 100) : 0,
            'status' => $this->status,
            'result' => $this->result,
            'failureReason' => $this->failureReason,
            'startedAt' => $this->startedAt->format(\DATE_ATOM),
            'finishedAt' => $this->finishedAt?->format(\DATE_ATOM),
        ];
    }
}
