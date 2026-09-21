<?php

declare(strict_types=1);

namespace App\Module\Webhook\Service;

use App\Module\Webhook\Entity\WebhookDelivery;
use App\Module\Webhook\Message\DeliverWebhook;
use App\Module\Webhook\Repository\WebhookEndpointRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use OpenEnu\Kernel\Attribute\InfrastructureWrite;
use OpenEnu\Kernel\Doctrine\ScopeContext;
use OpenEnu\Kernel\Event\DomainEvent;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Turns any domain event into deliveries, for whoever asked for it.
 *
 * Subscribes to `DomainEvent` itself rather than to named events, so a module
 * that adds an event gets webhooks for free - the subscription lives in the
 * tenant's endpoint row, not in code (.ai/platform/PLAN.md §6.10).
 *
 * Runs on the outbox worker, alongside `ClientBroadcaster`. Like that one, it
 * **must not throw**: a webhook nobody is listening for cannot be allowed to
 * fail the event that carried it, and the delivery itself has its own retries.
 */
#[AsMessageHandler]
final readonly class WebhookPublisher
{
    public function __construct(
        private WebhookEndpointRepository $endpoints,
        private EntityManagerInterface $em,
        private MessageBusInterface $jobs,
        private ScopeContext $scope,
        private LoggerInterface $logger,
    ) {
    }

    #[InfrastructureWrite(reason: 'recording an outbound delivery attempt; the event that caused it is already audited')]
    public function __invoke(DomainEvent $event): void
    {
        $tenantId = $this->scope->tenantId();

        if ($tenantId === null) {
            // No tenant means no endpoints to consult. A warning rather than a
            // failure: the event itself was still valid.
            $this->logger->warning('webhook.no_tenant', ['event' => $event->eventName()]);

            return;
        }

        try {
            foreach ($this->endpoints->subscribedTo($event->eventName()) as $endpoint) {
                $delivery = new WebhookDelivery(
                    $tenantId,
                    (string) $endpoint->id(),
                    $event->eventName(),
                    // `payload()` and not the object: what crosses to a third
                    // party is a decision the event makes explicitly.
                    ['event' => $event->eventName(), 'subjectId' => $event->subjectId,
                     'occurredAt' => $event->occurredAt->format(\DATE_ATOM), 'data' => $event->payload()],
                );

                $this->em->persist($delivery);
                $this->em->flush();

                $this->jobs->dispatch(new DeliverWebhook((string) $delivery->id()));
            }
        } catch (\Throwable $e) {
            $this->logger->error('webhook.publish_failed', [
                'event' => $event->eventName(),
                'error' => $e->getMessage(),
            ]);
        }
    }
}
