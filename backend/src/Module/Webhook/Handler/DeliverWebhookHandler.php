<?php

declare(strict_types=1);

namespace App\Module\Webhook\Handler;

use App\Module\Webhook\Entity\WebhookDelivery;
use App\Module\Webhook\Message\DeliverWebhook;
use App\Module\Webhook\Repository\WebhookDeliveryRepository;
use App\Module\Webhook\Repository\WebhookEndpointRepository;
use App\Module\Webhook\Service\WebhookDeliveryFailed;
use App\Module\Webhook\Service\WebhookSigner;
use Doctrine\ORM\EntityManagerInterface;
use OpenEnu\Kernel\Attribute\InfrastructureWrite;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The POST, and the record of how it went.
 *
 * Throws on failure, deliberately: Messenger's retry strategy on the `jobs`
 * transport is what implements the backoff, so re-queuing by hand here would be
 * a second, worse retry mechanism running alongside the real one.
 *
 * It does NOT record the failure itself, and that is not an oversight. Handlers
 * run inside `doctrine_transaction`, so a row written here and then followed by
 * a throw is rolled back with it - the log would read `attempts: 0` while the
 * worker logged five retries. `Listener\RecordFailedDeliveryListener` writes it
 * after the rollback instead.
 */
#[AsMessageHandler]
final readonly class DeliverWebhookHandler
{
    private const int TIMEOUT_SECONDS = 10;

    public function __construct(
        private WebhookDeliveryRepository $deliveries,
        private WebhookEndpointRepository $endpoints,
        private HttpClientInterface $http,
        private WebhookSigner $signer,
        private EntityManagerInterface $em,
    ) {
    }

    #[InfrastructureWrite(reason: 'recording the outcome of an outbound HTTP call, not a domain change')]
    public function __invoke(DeliverWebhook $job): void
    {
        $delivery = $this->deliveries->get($job->deliveryId);
        $endpoint = $delivery === null ? null : $this->endpoints->get($delivery->endpointId());

        if ($delivery === null || $endpoint === null || !$endpoint->isActive()) {
            // Deleted or switched off while this sat in the queue. Nothing to do
            // and nothing to retry - throwing would retry it five times first.
            return;
        }

        $payload = json_encode($delivery->payload(), \JSON_THROW_ON_ERROR);
        $headers = $this->signer->headers((string) $delivery->id(), $payload, $endpoint->secret());

        try {
            $response = $this->http->request('POST', $endpoint->url(), [
                'headers' => [...$headers, 'Content-Type' => 'application/json'],
                'body' => $payload,
                'timeout' => self::TIMEOUT_SECONDS,
            ]);

            $status = $response->getStatusCode();
        } catch (ExceptionInterface $e) {
            throw new WebhookDeliveryFailed((string) $delivery->id(), null, $e->getMessage());
        }

        if ($status >= 200 && $status < 300) {
            $delivery->succeeded($status);
            $this->em->flush();

            return;
        }

        // Anything else is a failure the receiver reported. Its body travels on
        // the exception, because that is what the tenant will ask about and the
        // listener is the only thing that can still write it.
        throw new WebhookDeliveryFailed((string) $delivery->id(), $status, $this->safeBody($response));
    }

    private function safeBody(object $response): string
    {
        try {
            return method_exists($response, 'getContent') ? (string) $response->getContent(false) : '';
        } catch (\Throwable) {
            // A body that cannot be read must not replace the status code we
            // already know with an exception nobody can act on.
            return '';
        }
    }
}
