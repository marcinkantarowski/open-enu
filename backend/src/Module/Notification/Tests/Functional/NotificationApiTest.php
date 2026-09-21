<?php

declare(strict_types=1);

namespace App\Module\Notification\Tests\Functional;

use App\Module\Notification\Entity\Notification;
use App\Tests\Support\ApiTestCase;

/**
 * Your own feed, and nobody else's.
 *
 * The routes take no user id - it comes from the session every time - so the
 * assertions that matter here are about what a second person's session sees.
 */
final class NotificationApiTest extends ApiTestCase
{
    /**
     * Declared by the Webhook module. Named as a string rather than imported:
     * a type is a published name, and a test reaching into another module for
     * the constant would be asserting that the constant exists, not the name.
     */
    private const string DECLARED_TYPE = 'webhook.delivery_failed';

    protected function fixtures(): array
    {
        return [Notification::class];
    }

    public function testTheFeedCarriesTranslationKeysRatherThanSentences(): void
    {
        $this->givenATenant();
        $user = $this->givenIAmSignedIn();
        $this->notify((string) $user->id(), self::DECLARED_TYPE, ['event' => 'example.project.created', 'attempts' => 5]);

        $this->get('/api/notifications');

        self::assertResponseIsSuccessful();
        $item = $this->json()['items'][0];

        // Keys and arguments, never prose. The row is written once and read by
        // whoever opens it, in whatever language they have chosen - storing a
        // sentence would freeze it in the language of the worker that wrote it.
        self::assertSame('notification.webhook.delivery_failed.title', $item['titleKey']);
        self::assertSame(5, $item['context']['attempts']);
        self::assertFalse($item['read']);
        self::assertSame(1, $this->json()['meta']['unread']);
    }

    public function testAnUndeclaredTypeDegradesToItsOwnName(): void
    {
        $this->givenATenant();
        $user = $this->givenIAmSignedIn();
        $this->notify((string) $user->id(), 'retired.type.from.an.older.version');

        $this->get('/api/notifications');

        // A type removed in a later version leaves rows behind. Falling back to
        // the name keeps the feed readable instead of 500ing on history.
        self::assertResponseIsSuccessful();
        self::assertSame('retired.type.from.an.older.version', $this->json()['items'][0]['titleKey']);
    }

    public function testMarkingOneReadLeavesTheRest(): void
    {
        $this->givenATenant();
        $user = $this->givenIAmSignedIn();
        $first = $this->notify((string) $user->id(), self::DECLARED_TYPE);
        $this->notify((string) $user->id(), self::DECLARED_TYPE);

        $this->post('/api/notifications/' . $first . '/read');

        self::assertResponseIsSuccessful();
        self::assertSame('read', $this->json()['status']);

        $this->get('/api/notifications');
        self::assertSame(1, $this->json()['meta']['unread']);
    }

    public function testReadAllEmptiesTheCounter(): void
    {
        $this->givenATenant();
        $user = $this->givenIAmSignedIn();
        $this->notify((string) $user->id(), self::DECLARED_TYPE);
        $this->notify((string) $user->id(), self::DECLARED_TYPE);

        $this->post('/api/notifications/read-all');

        self::assertResponseIsSuccessful();
        self::assertSame(0, $this->json()['unread']);

        $this->get('/api/notifications');
        self::assertSame(0, $this->json()['meta']['unread']);
    }

    public function testSomebodyElsesNotificationIsNotFoundRatherThanForbidden(): void
    {
        $this->givenATenant();
        $colleague = $this->makeUser('colleague@example.test');
        $theirs = $this->notify((string) $colleague->id(), self::DECLARED_TYPE);

        $this->givenIAmSignedIn();

        $this->get('/api/notifications');
        self::assertSame([], $this->json()['items'], 'A feed shows one person their own notifications.');

        $this->post('/api/notifications/' . $theirs . '/read');

        // Indistinguishable from a notification that does not exist. A 403 here
        // would turn the endpoint into a way to discover other people's ids.
        self::assertResponseStatusCodeSame(404);
    }

    /** @param array<string, scalar|null> $context */
    private function notify(string $userId, string $type, array $context = []): string
    {
        $notification = new Notification($this->tenantId, $userId, $type, $context);

        $this->em->persist($notification);
        $this->em->flush();

        return (string) $notification->id();
    }
}
