<?php

declare(strict_types=1);

namespace App\Module\Webhook\Service;

/**
 * A delivery attempt that did not succeed, carrying what the log needs.
 *
 * It exists because of one fact about the worker: every handler runs inside
 * `doctrine_transaction`, so **a handler that records its own failure and then
 * throws records nothing** - the throw rolls the row back with it. The attempt
 * therefore has to be written outside that transaction, by
 * `Listener\RecordFailedDeliveryListener`, and this exception is how the status
 * code and body reach it.
 *
 * Lives in `Service/` because module directories are a fixed set and none of
 * them is `Exception/`; adding one would change the convention for every module.
 */
final class WebhookDeliveryFailed extends \RuntimeException
{
    public function __construct(
        public readonly string $deliveryId,
        public readonly ?int $responseCode,
        string $reason,
    ) {
        parent::__construct(sprintf(
            'Webhook delivery %s failed%s: %s',
            $deliveryId,
            $responseCode === null ? '' : ' with ' . $responseCode,
            $reason,
        ));
    }

    public function reason(): string
    {
        return $this->getMessage();
    }
}
