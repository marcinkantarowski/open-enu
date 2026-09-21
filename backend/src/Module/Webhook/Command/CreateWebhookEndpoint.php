<?php

declare(strict_types=1);

namespace App\Module\Webhook\Command;

use OpenEnu\Kernel\Command\CommandInterface;

final readonly class CreateWebhookEndpoint implements CommandInterface
{
    /** @param list<string> $events */
    public function __construct(
        public string $url,
        public array $events,
    ) {
    }

    public function auditAction(): string
    {
        return 'webhook.endpoint.created';
    }

    public function auditSubjectId(): ?string
    {
        return null;
    }
}
