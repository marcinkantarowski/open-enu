<?php

declare(strict_types=1);

namespace App\Module\Audit\Tests\Functional;

use App\Module\Audit\Entity\AuditEntry;
use App\Tests\Support\ApiTestCase;

/**
 * The trail a tenant can read about itself.
 *
 * The write under test is made through HTTP rather than by constructing an
 * entry: what is being asserted is that the middleware records real traffic,
 * and a hand-built row would only prove this file can build one.
 */
final class AuditApiTest extends ApiTestCase
{
    protected function fixtures(): array
    {
        return [AuditEntry::class];
    }

    public function testAWriteThroughTheApiAppearsWithBothVersions(): void
    {
        $this->givenATenant();
        $user = $this->givenIAmSignedIn();

        // Driven through the reference module's endpoints rather than any of
        // this module's own: what is under test is that ORDINARY traffic is
        // recorded, and a write made specially for the audit test would be the
        // one kind of write that cannot prove that.
        $this->post('/api/projects', ['name' => 'Before']);
        self::assertResponseStatusCodeSame(201);
        $id = (string) $this->json()['id'];

        $this->patch('/api/projects/' . $id, ['name' => 'After']);
        self::assertResponseIsSuccessful();

        $this->get('/api/audit');

        self::assertResponseIsSuccessful();
        $entry = $this->json()['items'][0] ?? null;
        self::assertIsArray($entry, 'A write through the bus must leave an entry.');

        // Named after the command, attributed to a real person, and carrying
        // both versions - an audit line that says only "something was updated"
        // answers no question anybody actually asks.
        self::assertSame('example.project.renamed', $entry['action']);
        self::assertSame((string) $user->id(), $entry['actorId']);
        self::assertSame($id, $entry['subjectId']);
        self::assertTrue($entry['succeeded']);
        self::assertSame('Before', $entry['before']['name'] ?? null);
        self::assertSame('After', $entry['after']['name'] ?? null);

        // Newest first, and the create is still there underneath it: the trail
        // is append-only, so a correction never replaces what it corrects.
        self::assertSame('example.project.created', $this->json()['items'][1]['action']);
    }

    public function testTheTrailIsNarrowedToTheCallersTenant(): void
    {
        $this->givenATenant('alpha');
        $this->givenIAmSignedIn();
        $this->post('/api/projects', ['name' => "alpha's project"]);

        $this->givenATenant('beta');
        $this->givenIAmSignedIn(email: 'beta-owner@example.test');

        $this->get('/api/audit');

        self::assertResponseIsSuccessful();
        // The entity is deliberately UNSCOPED so an operator can investigate
        // across tenants; the narrowing lives in the controller. That makes
        // this the assertion that keeps one tenant out of another's history.
        foreach ($this->json()['items'] as $entry) {
            self::assertSame($this->tenantId, $entry['tenantId']);
        }
    }

    public function testTheTrailIsNotReadableWithoutASession(): void
    {
        $this->givenATenant();

        $this->get('/api/audit');

        // Anonymous, so there is no tenant in scope either - and a trail that
        // answered here would answer with every tenant's history at once.
        self::assertResponseStatusCodeSame(401);
    }
}
