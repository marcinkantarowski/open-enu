<?php

declare(strict_types=1);

namespace App\Module\Webhook\Controller\Api;

use App\Module\Webhook\Command\CreateWebhookEndpoint;
use App\Module\Webhook\Command\DeactivateWebhookEndpoint;
use App\Module\Webhook\Command\RedeliverWebhook;
use App\Module\Webhook\Repository\WebhookDeliveryRepository;
use App\Module\Webhook\Repository\WebhookEndpointRepository;
use OpenEnu\Kernel\Attribute\DeniedUnderImpersonation;
use OpenEnu\Kernel\Command\CommandBusInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** Thin by rule: validate, delegate, serialize. */
final readonly class WebhookController
{
    public function __construct(
        private WebhookEndpointRepository $endpoints,
        private WebhookDeliveryRepository $deliveries,
        private CommandBusInterface $commands,
    ) {
    }

    #[Route('/api/webhooks', name: 'webhook_index', methods: ['GET'])]
    #[IsGranted('webhook.view')]
    public function index(): JsonResponse
    {
        return new JsonResponse([
            'items' => array_map(static fn (object $e): array => $e->toArray(), $this->endpoints->all()),
        ]);
    }

    #[Route('/api/webhooks', name: 'webhook_create', methods: ['POST'])]
    #[IsGranted('webhook.manage')]
    // Support may look at where a tenant's events go; support may not add a
    // destination for them while wearing that tenant's face.
    #[DeniedUnderImpersonation(because: 'Adding a webhook endpoint is not available while viewing as another user.')]
    public function create(Request $request): JsonResponse
    {
        /** @var array<string, mixed> $body */
        $body = json_decode($request->getContent() ?: '{}', true) ?: [];

        $url = $body['url'] ?? null;
        $events = $body['events'] ?? null;

        if (!\is_string($url) || !\is_array($events)) {
            throw new BadRequestHttpException('"url" and "events" are required.');
        }

        return new JsonResponse(
            $this->commands->dispatch(new CreateWebhookEndpoint(
                url: trim($url),
                events: array_values(array_filter($events, is_string(...))),
            )),
            Response::HTTP_CREATED,
        );
    }

    #[Route('/api/webhooks/{id}', name: 'webhook_deactivate', methods: ['DELETE'])]
    #[IsGranted('webhook.manage')]
    #[DeniedUnderImpersonation(because: 'Removing a webhook endpoint is not available while viewing as another user.')]
    public function deactivate(string $id): JsonResponse
    {
        return new JsonResponse($this->commands->dispatch(new DeactivateWebhookEndpoint($id)));
    }

    #[Route('/api/webhooks/deliveries', name: 'webhook_deliveries', methods: ['GET'])]
    #[IsGranted('webhook.view')]
    public function deliveries(Request $request): JsonResponse
    {
        $endpointId = $request->query->getString('endpointId') ?: null;

        return new JsonResponse([
            'items' => array_map(
                static fn (object $d): array => $d->toArray(),
                $this->deliveries->recent($endpointId),
            ),
        ]);
    }

    #[Route('/api/webhooks/deliveries/{id}/redeliver', name: 'webhook_redeliver', methods: ['POST'])]
    #[IsGranted('webhook.manage')]
    public function redeliver(string $id): JsonResponse
    {
        // 202: queued, not sent. The log is where the outcome appears.
        return new JsonResponse($this->commands->dispatch(new RedeliverWebhook($id)), Response::HTTP_ACCEPTED);
    }
}
