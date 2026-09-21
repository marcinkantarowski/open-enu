<?php

declare(strict_types=1);

namespace App\Module\Webhook\Listener;

use App\Module\Webhook\Message\DeliverWebhook;
use App\Module\Webhook\Repository\WebhookDeliveryRepository;
use App\Module\Webhook\Repository\WebhookEndpointRepository;
use App\Module\Webhook\Service\WebhookDeliveryFailed;
use App\Module\Webhook\Service\WebhookNotifications;
use Doctrine\ORM\EntityManagerInterface;
use OpenEnu\Kernel\Attribute\InfrastructureWrite;
use OpenEnu\Kernel\Doctrine\ScopeContext;
use OpenEnu\Kernel\Notification\NotifierInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\Stamp\BusNameStamp;

/**
 * Writes the attempt that the handler could not write itself.
 *
 * Every handler runs inside `doctrine_transaction`, so recording a failure and
 * then throwing loses the record: the throw rolls back the same transaction the
 * write went into. The delivery log would show `attempts: 0` while the worker
 * logged five retries - which is precisely the discrepancy this module exists to
 * avoid.
 *
 * This listener runs after that rollback, in a transaction of its own, and is
 * the only correct place to persist it.
 */
#[AsEventListener(event: WorkerMessageFailedEvent::class)]
final readonly class RecordFailedDeliveryListener
{
    public function __construct(
        private WebhookDeliveryRepository $deliveries,
        private WebhookEndpointRepository $endpoints,
        private NotifierInterface $notifier,
        private EntityManagerInterface $em,
        private ScopeContext $scope,
    ) {
    }

    #[InfrastructureWrite(reason: 'recording an outbound attempt after its transaction rolled back')]
    public function __invoke(WorkerMessageFailedEvent $event): void
    {
        $message = $event->getEnvelope()->getMessage();

        if (!$message instanceof DeliverWebhook) {
            return;
        }

        $failure = $event->getThrowable();
        if ($failure instanceof HandlerFailedException) {
            // Messenger wraps whatever the handler threw.
            $failure = $failure->getPrevious() ?? $failure;
        }

        $this->scope->runUnscoped('recording a delivery attempt for a tenant the worker is no longer scoped to', function () use ($message, $failure): void {
            $delivery = $this->deliveries->get($message->deliveryId);

            if ($delivery === null) {
                return;
            }

            $delivery->failed(
                $failure instanceof WebhookDeliveryFailed ? $failure->responseCode : null,
                $failure->getMessage(),
            );

            $this->em->flush();

            if ($delivery->toArray()['status'] !== 'failed') {
                // Still retrying. Telling somebody about an attempt that may yet
                // succeed is how a notification feed becomes noise people mute.
                return;
            }

            // Out of retries. Nobody finds out otherwise - a dead endpoint is
            // silent by definition, and the tenant notices days later when data
            // is missing.
            $this->notifier->toTenant($delivery->tenantId(), WebhookNotifications::DELIVERY_FAILED, [
                'event' => $delivery->eventName(),
                'url' => $this->endpoints->get($delivery->endpointId())?->url() ?? '',
                'attempts' => $delivery->attempts(),
            ]);
        });
    }
}
