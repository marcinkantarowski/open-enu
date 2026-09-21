<?php

declare(strict_types=1);

namespace App\Module\Webhook\Tests\Functional;

use App\Module\Example\Event\ProjectCreated;
use App\Module\Tenant\Entity\Tenant;
use App\Module\Webhook\Entity\WebhookDelivery;
use App\Module\Webhook\Entity\WebhookEndpoint;
use App\Module\Webhook\Handler\DeliverWebhookHandler;
use App\Module\Webhook\Message\DeliverWebhook;
use App\Module\Webhook\Repository\WebhookDeliveryRepository;
use App\Module\Webhook\Repository\WebhookEndpointRepository;
use App\Module\Webhook\Listener\RecordFailedDeliveryListener;
use App\Module\Webhook\Service\WebhookDeliveryFailed;
use App\Module\Webhook\Service\WebhookPublisher;
use App\Module\Webhook\Service\WebhookSigner;
use Doctrine\ORM\EntityManagerInterface;
use OpenEnu\Kernel\Doctrine\ScopeContext;
use OpenEnu\Kernel\Event\DomainEvent;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * An event leaving the system, with a real signature and a real log.
 *
 * The HTTP client is a mock and nothing else is: the publisher, the repositories,
 * the entities and the signer are all the real ones. The mock is the *receiver*,
 * which is the one participant that genuinely lives outside this process.
 */
final class WebhookDeliveryTest extends KernelTestCase
{
    private const string SECRET_PREFIX = 'whsec_';
    private const string URL = 'https://receiver.example.test/hooks';

    private EntityManagerInterface $em;
    private ScopeContext $scope;
    private string $tenantId;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        $em = $container->get('doctrine.orm.entity_manager');
        \assert($em instanceof EntityManagerInterface);
        $this->em = $em;

        $scope = $container->get(ScopeContext::class);
        \assert($scope instanceof ScopeContext);
        $this->scope = $scope;

        $this->scope->runUnscoped('test fixture reset', function (): void {
            foreach ([WebhookDelivery::class, WebhookEndpoint::class, Tenant::class] as $entity) {
                $this->em->createQuery('DELETE FROM ' . $entity . ' e')->execute();
            }
        });

        $tenant = new Tenant('webhook-test', 'Webhook Test');
        $tenant->activate();
        $this->em->persist($tenant);
        $this->em->flush();

        $this->tenantId = (string) $tenant->id();
        $this->scope->enter([ScopeContext::TENANT => $this->tenantId]);
    }

    public function testASubscribedEventBecomesASignedRequestTheReceiverCanVerify(): void
    {
        $secret = self::SECRET_PREFIX . 'functional';
        $endpoint = $this->makeEndpoint($secret, ['example.project.created']);

        $this->publish(new ProjectCreated('0192f000-0000-7000-8000-00000000a001', 'Harbour refit'));

        $delivery = $this->deliveries()->recent()[0] ?? null;
        self::assertNotNull($delivery, 'The publisher must record a delivery for a subscribed event.');
        self::assertSame('example.project.created', $delivery->eventName());

        $seen = [];
        $this->deliver($delivery, new MockHttpClient(function (string $method, string $url, array $options) use (&$seen): MockResponse {
            $seen = ['method' => $method, 'url' => $url, 'headers' => $options['normalized_headers'] ?? [],
                     'body' => $options['body'] ?? ''];

            return new MockResponse('', ['http_code' => 200]);
        }));

        self::assertSame('POST', $seen['method']);
        self::assertSame(self::URL, $seen['url']);

        $header = fn (string $name): string => trim(explode(':', $seen['headers'][$name][0] ?? '', 2)[1] ?? '');

        // Verified the way a receiver would: recompute the HMAC from the spec,
        // using only the headers and body that actually went over the wire.
        $expected = 'v1,' . base64_encode(hash_hmac(
            'sha256',
            $header('webhook-id') . '.' . $header('webhook-timestamp') . '.' . $seen['body'],
            $secret,
            true,
        ));

        self::assertSame($expected, $header('webhook-signature'));
        self::assertSame((string) $delivery->id(), $header('webhook-id'));

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $seen['body'], true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame('example.project.created', $body['event']);
        self::assertSame('Harbour refit', $body['data']['name']);

        $this->em->refresh($delivery);
        self::assertSame('delivered', $delivery->toArray()['status']);
        self::assertSame(200, $delivery->toArray()['responseCode']);
        self::assertSame(1, $delivery->attempts());
        self::assertSame(self::URL, $endpoint->url());
    }

    public function testAFailingReceiverIsRetriedAndEndsUpVisibleInTheLog(): void
    {
        $this->makeEndpoint(self::SECRET_PREFIX . 'failing', ['example.project.created']);
        $this->publish(new ProjectCreated('0192f000-0000-7000-8000-00000000a002', 'Doomed'));

        $delivery = $this->deliveries()->recent()[0];
        $deliveryId = (string) $delivery->id();
        $refusing = new MockHttpClient(static fn (): MockResponse => new MockResponse('upstream exploded', ['http_code' => 500]));

        // Five attempts, because that is the `jobs` transport's retry_strategy.
        // Messenger drives the real backoff; the loop here stands in for the
        // worker so the LOG's behaviour across attempts can be asserted.
        for ($attempt = 1; $attempt <= WebhookDelivery::MAX_ATTEMPTS; ++$attempt) {
            $delivery = $this->deliveries()->get($deliveryId);
            \assert($delivery !== null);

            try {
                $this->deliver($delivery, $refusing);
                self::fail('A 500 must throw, or Messenger never retries.');
            } catch (WebhookDeliveryFailed $e) {
                self::assertSame(500, $e->responseCode);

                // The handler cannot write this itself: it runs inside
                // `doctrine_transaction`, so a row written before the throw is
                // rolled back with it. The worker's failure event is what
                // persists the attempt, and that is what is replayed here.
                $this->recordFailure($delivery, $e);
            }

            // Re-read rather than refresh a held reference: the listener runs
            // through `runUnscoped`, which clears the identity map so a stale
            // object cannot outlive the scope it was loaded in.
            $row = $this->deliveries()->get((string) $deliveryId)?->toArray() ?? [];

            self::assertSame($attempt, $row['attempts']);
            self::assertSame(500, $row['responseCode']);
            self::assertStringContainsString('upstream exploded', (string) $row['responseBody']);

            // Still `pending` while retries remain: a queued retry shown as a
            // failure sends people chasing a non-problem.
            self::assertSame(
                $attempt < WebhookDelivery::MAX_ATTEMPTS ? 'pending' : 'failed',
                $row['status'],
            );
        }
    }

    public function testAnEventNobodySubscribedToProducesNoDelivery(): void
    {
        $this->makeEndpoint(self::SECRET_PREFIX . 'other', ['billing.invoice.paid']);
        $this->publish(new ProjectCreated('0192f000-0000-7000-8000-00000000a003', 'Ignored'));

        self::assertSame([], $this->deliveries()->recent());
    }

    public function testADeactivatedEndpointStopsReceiving(): void
    {
        $endpoint = $this->makeEndpoint(self::SECRET_PREFIX . 'off', ['example.project.created']);
        $endpoint->deactivate();
        $this->em->flush();

        $this->publish(new ProjectCreated('0192f000-0000-7000-8000-00000000a004', 'Quiet'));

        self::assertSame([], $this->deliveries()->recent());
    }

    // ── helpers ─────────────────────────────────────────────────────────────

    /** @param list<string> $events */
    private function makeEndpoint(string $secret, array $events): WebhookEndpoint
    {
        $endpoint = new WebhookEndpoint($this->tenantId, self::URL, $events, $secret);

        $this->em->persist($endpoint);
        $this->em->flush();

        return $endpoint;
    }

    private function publish(DomainEvent $event): void
    {
        $publisher = self::getContainer()->get(WebhookPublisher::class);
        \assert($publisher instanceof WebhookPublisher);
        $publisher($event);
    }

    private function deliver(WebhookDelivery $delivery, HttpClientInterface $http): void
    {
        $handler = new DeliverWebhookHandler(
            $this->deliveries(),
            $this->endpoints(),
            $http,
            new WebhookSigner(),
            $this->em,
        );

        $handler(new DeliverWebhook((string) $delivery->id()));
    }

    /** Stands in for the worker emitting WorkerMessageFailedEvent. */
    private function recordFailure(WebhookDelivery $delivery, \Throwable $failure): void
    {
        $listener = self::getContainer()->get(RecordFailedDeliveryListener::class);
        \assert($listener instanceof RecordFailedDeliveryListener);

        $listener(new WorkerMessageFailedEvent(
            new Envelope(new DeliverWebhook((string) $delivery->id())),
            'jobs',
            $failure,
        ));
    }

    private function deliveries(): WebhookDeliveryRepository
    {
        $repository = self::getContainer()->get(WebhookDeliveryRepository::class);
        \assert($repository instanceof WebhookDeliveryRepository);

        return $repository;
    }

    private function endpoints(): WebhookEndpointRepository
    {
        $repository = self::getContainer()->get(WebhookEndpointRepository::class);
        \assert($repository instanceof WebhookEndpointRepository);

        return $repository;
    }
}
