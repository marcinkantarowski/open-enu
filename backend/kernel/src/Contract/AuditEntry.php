<?php

declare(strict_types=1);

namespace OpenEnu\Kernel\Contract;

/**
 * One recorded change.
 *
 * Deliberately a value object in the kernel rather than an entity in a module:
 * the kernel records audit entries from the command bus and must not depend on
 * whatever module eventually stores them (ADR-0016).
 */
final readonly class AuditEntry
{
    /**
     * @param string                    $action     dotted, stable: `billing.invoice.void`
     * @param array<string, mixed>|null $before     state before, when the handler provided it
     * @param array<string, mixed>|null $after      state after
     * @param array<string, mixed>      $context    anything else worth keeping
     */
    public function __construct(
        public string $action,
        public ?string $subjectId = null,
        public ?string $actorId = null,
        public ?string $tenantId = null,
        public ?string $requestId = null,
        public bool $succeeded = true,
        public ?string $failureReason = null,
        public ?array $before = null,
        public ?array $after = null,
        public array $context = [],
        public ?\DateTimeImmutable $at = null,
    ) {
    }

    public function failed(string $reason): self
    {
        return new self(
            $this->action, $this->subjectId, $this->actorId, $this->tenantId, $this->requestId,
            false, $reason, $this->before, $this->after, $this->context, $this->at,
        );
    }
}
