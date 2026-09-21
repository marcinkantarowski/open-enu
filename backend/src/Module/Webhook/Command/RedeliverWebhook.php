<?php

declare(strict_types=1);

namespace App\Module\Webhook\Command;

use OpenEnu\Kernel\Command\CommandInterface;

final readonly class RedeliverWebhook implements CommandInterface
{
    public function __construct(public string $deliveryId)
    {
    }

    public function auditAction(): string
    {
        return 'webhook.delivery.redelivered';
    }

    public function auditSubjectId(): string
    {
        return $this->deliveryId;
    }
}
