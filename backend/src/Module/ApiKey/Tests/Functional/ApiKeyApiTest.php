<?php

declare(strict_types=1);

namespace App\Module\ApiKey\Tests\Functional;

use App\Module\ApiKey\Entity\ApiKey;
use App\Module\Identity\Entity\Membership;
use App\Tests\Support\ApiTestCase;

/**
 * Minting, listing and revoking machine credentials.
 *
 * The interesting assertions are the refusals: what a key may be granted, who
 * may mint one, and that the secret is shown exactly once.
 */
final class ApiKeyApiTest extends ApiTestCase
{
    protected function fixtures(): array
    {
        return [ApiKey::class];
    }

    public function testAKeyIsCreatedAndItsSecretIsReturnedExactlyOnce(): void
    {
        $this->givenATenant();
        $this->givenIAmSignedIn();

        $this->post('/api/api-keys', ['name' => 'CI', 'permissions' => ['example.view']]);

        self::assertResponseStatusCodeSame(201);
        $created = $this->json();
        self::assertStringStartsWith(ApiKey::PREFIX, (string) $created['secret']);
        self::assertSame(['example.view'], $created['permissions']);

        // Listing never carries it again. Storing the secret would make every
        // backup, log and support query a set of working credentials.
        $this->get('/api/api-keys');

        self::assertResponseIsSuccessful();
        $listed = $this->json()['items'][0];
        self::assertArrayNotHasKey('secret', $listed);
        self::assertSame(substr((string) $created['secret'], 0, 12), $listed['prefix']);
    }

    public function testAnUndeclaredPermissionIsRefused(): void
    {
        $this->givenATenant();
        $this->givenIAmSignedIn();

        $this->post('/api/api-keys', ['name' => 'Typo', 'permissions' => ['exmaple.view']]);

        // Rejected at creation rather than ignored: a key holding a permission
        // no module declares grants nothing, and that surfaces days later as an
        // unexplained 403 on the customer's side.
        self::assertResponseStatusCodeSame(400);
    }

    public function testAKeyWithNoPermissionsIsRefused(): void
    {
        $this->givenATenant();
        $this->givenIAmSignedIn();

        $this->post('/api/api-keys', ['name' => 'Empty', 'permissions' => []]);

        self::assertResponseStatusCodeSame(400);
    }

    public function testRevokingAKeyLeavesItVisibleAndMarked(): void
    {
        $this->givenATenant();
        $this->givenIAmSignedIn();

        $this->post('/api/api-keys', ['name' => 'Temporary', 'permissions' => ['example.view']]);
        $id = (string) $this->json()['id'];

        $this->delete('/api/api-keys/' . $id);
        self::assertResponseIsSuccessful();
        self::assertSame('revoked', $this->json()['status']);

        // Marked, not deleted. A revoked credential that vanishes takes its
        // audit trail with it, and "was this key ever real?" stops having an
        // answer.
        $this->get('/api/api-keys');
        self::assertTrue($this->json()['items'][0]['revoked']);
    }

    public function testAMemberMayNotMintAKey(): void
    {
        $this->givenATenant();
        $this->givenIAmSignedIn(Membership::ROLE_MEMBER, 'member@example.test');

        $this->post('/api/api-keys', ['name' => 'Escalation', 'permissions' => ['example.view']]);

        // `api_key.manage` is owner-only by policy: a credential that can mint
        // credentials removes the point of scoping them.
        self::assertResponseStatusCodeSame(403);
    }
}
