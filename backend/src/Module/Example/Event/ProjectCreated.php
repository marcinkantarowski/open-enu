<?php

declare(strict_types=1);

namespace App\Module\Example\Event;

use OpenEnu\Kernel\Event\ClientBroadcast;
use OpenEnu\Kernel\Event\DomainEvent;

/**
 * Announced when a project exists.
 *
 * `#[ClientBroadcast]` is opt-in and deliberate: it puts `payload()` on this
 * tenant's Mercure topic, where every open browser in that tenant receives it.
 * What goes in the payload is therefore a decision about a trust boundary, not a
 * reflection of the object.
 */
#[ClientBroadcast]
final readonly class ProjectCreated extends DomainEvent
{
    public function __construct(
        string $projectId,
        public string $name,
    ) {
        parent::__construct($projectId);
    }

    public function eventName(): string
    {
        return 'example.project.created';
    }

    public function payload(): array
    {
        // Deliberately not the whole record: the encrypted client reference and
        // the attributes stay server-side.
        return ['id' => $this->subjectId, 'name' => $this->name];
    }
}
