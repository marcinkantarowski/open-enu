<?php

declare(strict_types=1);

namespace App\Module\Webhook\Command;

use OpenEnu\Kernel\Command\CommandInterface;

/**
 * Deactivated, never deleted.
 *
 * The delivery log points at the endpoint, and "which URL did we send that to?"
 * is the question an incident actually asks.
 */
final readonly class DeactivateWebhookEndpoint implements CommandInterface
{
    public function __construct(public string $id)
    {
    }

    public function auditAction(): string
    {
        return 'webhook.endpoint.deactivated';
    }

    public function auditSubjectId(): string
    {
        return $this->id;
    }
}
