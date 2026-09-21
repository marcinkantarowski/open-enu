<?php

declare(strict_types=1);

namespace App\Module\Webhook\Handler;

use App\Module\Webhook\Command\RedeliverWebhook;
use App\Module\Webhook\Message\DeliverWebhook;
use App\Module\Webhook\Repository\WebhookDeliveryRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Send it again, on purpose.
 *
 * The same delivery row rather than a new one: the tenant is asking about *this*
 * event, and a second row would split its history in two.
 */
#[AsMessageHandler]
final readonly class RedeliverWebhookHandler
{
    public function __construct(
        private WebhookDeliveryRepository $deliveries,
        private EntityManagerInterface $em,
        private MessageBusInterface $jobs,
    ) {
    }

    /** @return array<string, mixed> */
    public function __invoke(RedeliverWebhook $command): array
    {
        $delivery = $this->deliveries->get($command->deliveryId) ?? throw new NotFoundHttpException('No such delivery.');

        $delivery->reset();
        $this->em->flush();

        $this->jobs->dispatch(new DeliverWebhook((string) $delivery->id()));

        return $delivery->toArray();
    }
}
