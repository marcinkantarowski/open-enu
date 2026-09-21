<?php

declare(strict_types=1);

namespace App\Module\Webhook\Message;

use OpenEnu\Kernel\Message\JobInterface;

/**
 * Deliver one recorded delivery.
 *
 * Carries only the delivery id: the payload, the endpoint and the attempt count
 * are all rows, and a retry three minutes later must act on what is true then,
 * not on a copy taken when the job was queued.
 */
final readonly class DeliverWebhook implements JobInterface
{
    public function __construct(public string $deliveryId)
    {
    }
}
