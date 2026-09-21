<?php

declare(strict_types=1);

namespace App\Module\Webhook\Handler;

use App\Module\Webhook\Command\DeactivateWebhookEndpoint;
use App\Module\Webhook\Repository\WebhookEndpointRepository;
use Doctrine\ORM\EntityManagerInterface;
use OpenEnu\Kernel\Command\SnapshotCollector;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class DeactivateWebhookEndpointHandler
{
    public function __construct(
        private WebhookEndpointRepository $endpoints,
        private EntityManagerInterface $em,
        private SnapshotCollector $snapshots,
    ) {
    }

    /** @return array<string, mixed> */
    public function __invoke(DeactivateWebhookEndpoint $command): array
    {
        $endpoint = $this->endpoints->get($command->id) ?? throw new NotFoundHttpException('No such endpoint.');

        $this->snapshots->before($endpoint->toArray());
        $endpoint->deactivate();
        $this->em->flush();

        $after = $endpoint->toArray();
        $this->snapshots->after($after);

        return $after;
    }
}
