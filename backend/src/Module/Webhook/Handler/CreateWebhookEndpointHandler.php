<?php

declare(strict_types=1);

namespace App\Module\Webhook\Handler;

use App\Module\Webhook\Command\CreateWebhookEndpoint;
use App\Module\Webhook\Entity\WebhookEndpoint;
use Doctrine\ORM\EntityManagerInterface;
use OpenEnu\Kernel\Command\SnapshotCollector;
use OpenEnu\Kernel\Doctrine\ScopeContext;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class CreateWebhookEndpointHandler
{
    public function __construct(
        private EntityManagerInterface $em,
        private ScopeContext $scope,
        private SnapshotCollector $snapshots,
    ) {
    }

    /** @return array<string, mixed> */
    public function __invoke(CreateWebhookEndpoint $command): array
    {
        $tenantId = $this->scope->tenantId()
            ?? throw new BadRequestHttpException('An endpoint belongs to a tenant; none is in scope.');

        if (!str_starts_with($command->url, 'https://')) {
            // HTTPS only. The payload is signed, not encrypted, so plain HTTP
            // publishes every event to anyone on the path.
            throw new BadRequestHttpException('A webhook endpoint must be an https:// URL.');
        }

        if ($command->events === []) {
            throw new BadRequestHttpException('Subscribe to at least one event - an endpoint with none receives nothing.');
        }

        // 32 bytes, generated here and never accepted from the client: a secret
        // the caller chose is one they may have chosen badly, or reused.
        $secret = 'whsec_' . rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');

        $endpoint = new WebhookEndpoint($tenantId, $command->url, $command->events, $secret);

        $this->em->persist($endpoint);
        $this->em->flush();

        $created = $endpoint->toArray();
        $this->snapshots->after($created);

        // The only time the secret leaves the server. It is encrypted at rest
        // and never returned again, which is the correct answer to "can you
        // resend it?" and the reason the response says so.
        return [...$created, 'secret' => $secret, 'warning' => 'Store this now - it cannot be shown again.'];
    }
}
