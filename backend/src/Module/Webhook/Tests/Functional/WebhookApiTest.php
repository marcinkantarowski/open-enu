<?php

declare(strict_types=1);

namespace App\Module\Webhook\Tests\Functional;

use App\Module\Identity\Entity\Membership;
use App\Module\Webhook\Entity\WebhookDelivery;
use App\Module\Webhook\Entity\WebhookEndpoint;
use App\Tests\Support\ApiTestCase;

/**
 * Managing where a tenant's events go.
 *
 * The delivery MECHANISM - signing, retries, the attempt log - is proved in
 * WebhookDeliveryTest against a mock receiver. This file is about the endpoints
 * a customer actually calls, and mostly about what they refuse.
 */
final class WebhookApiTest extends ApiTestCase
{
    protected function fixtures(): array
    {
        return [WebhookDelivery::class, WebhookEndpoint::class];
    }

    public function testAnEndpointIsCreatedAndItsSecretIsReturnedExactlyOnce(): void
    {
        $this->givenATenant();
        $this->givenIAmSignedIn();

        $this->post('/api/webhooks', [
            'url' => 'https://receiver.example.test/hooks',
            'events' => ['example.project.created'],
        ]);

        self::assertResponseStatusCodeSame(201);
        $created = $this->json();
        self::assertStringStartsWith('whsec_', (string) $created['secret']);

        // Encrypted at rest and never returned again. A secret a tenant can
        // re-read is a secret that lives in their browser history.
        $this->get('/api/webhooks');

        self::assertResponseIsSuccessful();
        $listed = $this->json()['items'][0];
        self::assertArrayNotHasKey('secret', $listed);
        self::assertTrue($listed['active']);
        self::assertSame(['example.project.created'], $listed['events']);
    }

    public function testAPlainHttpEndpointIsRefused(): void
    {
        $this->givenATenant();
        $this->givenIAmSignedIn();

        $this->post('/api/webhooks', ['url' => 'http://receiver.example.test/hooks', 'events' => ['example.project.created']]);

        // The payload is SIGNED, not encrypted: over plain HTTP every event is
        // published to anyone on the path, and the signature only proves who
        // wrote it, not who may read it.
        self::assertResponseStatusCodeSame(400);
    }

    public function testAnEndpointSubscribedToNothingIsRefused(): void
    {
        $this->givenATenant();
        $this->givenIAmSignedIn();

        $this->post('/api/webhooks', ['url' => 'https://receiver.example.test/hooks', 'events' => []]);

        self::assertResponseStatusCodeSame(400);
    }

    public function testDeactivationLeavesTheEndpointAndItsHistory(): void
    {
        $this->givenATenant();
        $this->givenIAmSignedIn();
        $id = $this->makeEndpoint();

        $this->delete('/api/webhooks/' . $id);
        self::assertResponseIsSuccessful();

        $this->get('/api/webhooks');
        // Deactivated, not deleted: the delivery log points at this row, and
        // removing it would erase the record of everything already sent.
        self::assertFalse($this->json()['items'][0]['active']);
    }

    public function testTheDeliveryLogIsFilterableByEndpoint(): void
    {
        $this->givenATenant();
        $this->givenIAmSignedIn();

        $mine = $this->makeEndpoint();
        $other = $this->makeEndpoint('https://elsewhere.example.test/hooks');
        $this->makeDelivery($mine, 'example.project.created');
        $this->makeDelivery($other, 'example.project.renamed');

        $this->get('/api/webhooks/deliveries?endpointId=' . $mine);

        self::assertResponseIsSuccessful();
        $items = $this->json()['items'];
        self::assertCount(1, $items);
        self::assertSame('example.project.created', $items[0]['event']);
        self::assertSame('pending', $items[0]['status']);
    }

    public function testRedeliveryQueuesTheSameRowRatherThanANewOne(): void
    {
        $this->givenATenant();
        $this->givenIAmSignedIn();

        $endpoint = $this->makeEndpoint();
        $deliveryId = $this->makeDelivery($endpoint, 'example.project.created', failed: true);

        $this->post('/api/webhooks/deliveries/' . $deliveryId . '/redeliver');

        // 202: queued, not sent. The outcome appears in the log, and pretending
        // otherwise would mean waiting on somebody else's server inside a
        // request.
        self::assertResponseStatusCodeSame(202);
        self::assertSame($deliveryId, $this->json()['id']);
        self::assertSame('pending', $this->json()['status']);

        $this->get('/api/webhooks/deliveries');
        // The same row, reset. A second row would split this event's history in
        // two, and "did it ever arrive?" would have two answers.
        self::assertCount(1, $this->json()['items']);
    }

    public function testAMemberMayReadTheLogButNotChangeWhereEventsGo(): void
    {
        $this->givenATenant();
        $this->givenIAmSignedIn(Membership::ROLE_MEMBER, 'member@example.test');

        $this->get('/api/webhooks/deliveries');
        self::assertResponseIsSuccessful();

        $this->post('/api/webhooks', ['url' => 'https://mine.example.test/hooks', 'events' => ['example.project.created']]);
        // `webhook.manage` is not a member's grant: a destination for every
        // event this workspace produces is an exfiltration route.
        self::assertResponseStatusCodeSame(403);
    }

    private function makeEndpoint(string $url = 'https://receiver.example.test/hooks'): string
    {
        $this->post('/api/webhooks', ['url' => $url, 'events' => ['example.project.created']]);
        self::assertResponseStatusCodeSame(201);

        return (string) $this->json()['id'];
    }

    private function makeDelivery(string $endpointId, string $event, bool $failed = false): string
    {
        $delivery = new WebhookDelivery($this->tenantId, $endpointId, $event, ['id' => 'x']);

        if ($failed) {
            $delivery->failed(500, 'receiver said no');
        }

        $this->em->persist($delivery);
        $this->em->flush();

        return (string) $delivery->id();
    }
}
