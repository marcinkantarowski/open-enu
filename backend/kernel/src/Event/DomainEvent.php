<?php

declare(strict_types=1);

namespace OpenEnu\Kernel\Event;

/**
 * Something that happened, stated as fact.
 *
 * Dispatched through the outbox: the row that queues it is written in the same
 * database transaction as the change that raised it, so a rollback takes the
 * event with it and a commit guarantees delivery (ADR-0009).
 *
 * Past tense, always - `InvoiceVoided`, not `VoidInvoice`. An event is a
 * statement about the world; a command is a request. Naming them the same way
 * is how the distinction erodes.
 */
abstract readonly class DomainEvent
{
    public \DateTimeImmutable $occurredAt;

    public function __construct(
        public string $subjectId,
        ?\DateTimeImmutable $occurredAt = null,
    ) {
        $this->occurredAt = $occurredAt ?? new \DateTimeImmutable();
    }

    /**
     * Stable dotted name, e.g. `billing.invoice.voided`.
     *
     * This is the wire contract - webhook subscriptions, browser subscriptions
     * and audit queries all key off it, so renaming one breaks consumers that do
     * not exist in this repository.
     */
    abstract public function eventName(): string;

    /**
     * What a subscriber outside this process receives.
     *
     * Deliberately explicit rather than reflected: an event crosses a trust
     * boundary when it reaches a browser or a webhook, and "everything public on
     * the object" is how internal fields leak.
     *
     * @return array<string, mixed>
     */
    abstract public function payload(): array;
}
