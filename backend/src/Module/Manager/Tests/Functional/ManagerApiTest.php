<?php

declare(strict_types=1);

namespace App\Module\Manager\Tests\Functional;

use App\Tests\Support\ApiTestCase;

/**
 * The operator console, through its own firewall.
 *
 * Every route here reads or changes data belonging to a tenant the caller is
 * not in. That is the whole point of the realm, and it is also why each test
 * below asserts the *effect* in the database rather than the response body:
 * a console that reports success and changes nothing is worse than one that
 * fails, because the operator stops looking.
 */
final class ManagerApiTest extends ApiTestCase
{
    protected function fixtures(): array
    {
        return [];
    }

    public function testAnOperatorSignsInToTheManagerRealm(): void
    {
        $manager = $this->givenIAmAnOperator();

        // `givenIAmAnOperator()` posted the login; this asserts what came back.
        self::assertResponseIsSuccessful();
        $body = $this->json();
        self::assertArrayHasKey('token', $body);
        // A manager token carries no tenant: there is nothing it is scoped to,
        // and the `aud` claim is what keeps it out of the tenant API (ADR-0007).
        self::assertArrayNotHasKey('tenantId', $body);
        self::assertSame((string) $manager->id(), $body['manager']['id'] ?? null);
    }

    public function testWrongOperatorCredentialsAreRefused(): void
    {
        $this->givenIAmAnOperator();

        $this->post('/api/manager/login', ['email' => 'ops@example.test', 'password' => 'not-the-password']);

        self::assertResponseStatusCodeSame(401);
    }

    public function testATenantIsDescribedWithItsMembers(): void
    {
        $tenantId = $this->givenATenant();
        $user = $this->makeUser('owner@example.test');
        $this->givenIAmAnOperator();

        $this->get('/api/manager/tenants/' . $tenantId);

        self::assertResponseIsSuccessful();
        $body = $this->json();
        self::assertSame('acme', $body['slug']);
        self::assertSame(
            [(string) $user->id()],
            array_map(static fn (array $m): string => $m['id'], $body['members']),
        );
    }

    public function testAnUnknownTenantIsNotFound(): void
    {
        $this->givenIAmAnOperator();

        $this->get('/api/manager/tenants/0192f000-0000-7000-8000-0000000000ff');

        self::assertResponseStatusCodeSame(404);
    }

    public function testSuspendingATenantTakesEffectAndIsAudited(): void
    {
        $tenantId = $this->givenATenant();
        $this->givenIAmAnOperator();

        $this->post('/api/manager/tenants/' . $tenantId . '/suspend');

        self::assertResponseIsSuccessful();
        self::assertSame('suspended', $this->json()['status']);

        // Read back from Postgres. This endpoint once answered exactly this way
        // while writing nothing at all, because the write happened after a
        // `runUnscoped()` had already detached the row.
        // See [[unflushed-work-does-not-survive-rununscoped]].
        self::assertFalse($this->reloadTenant()->isActive(), 'A suspended tenant must not still be active.');

        $this->get('/api/manager/audit?tenantId=' . $tenantId);

        self::assertResponseIsSuccessful();
        $actions = array_map(static fn (array $e): string => $e['action'], $this->json()['items']);
        self::assertContains('manager.tenant.suspended', $actions);
    }

    public function testImpersonationMintsAMarkedSessionForTheTenantUser(): void
    {
        $tenantId = $this->givenATenant();
        $user = $this->makeUser('subject@example.test');
        $operator = $this->givenIAmAnOperator();

        $this->post(sprintf('/api/manager/tenants/%s/users/%s/impersonate', $tenantId, $user->id()));

        self::assertResponseIsSuccessful();
        $body = $this->json();
        self::assertSame($tenantId, $body['tenantId']);
        // The client shows a persistent banner off this flag. An impersonated
        // session the user cannot see is what turns support access into a trust
        // problem.
        self::assertTrue($body['impersonating']);
        self::assertSame((string) $operator->id(), $body['operator']['id']);

        // No refresh cookie: the session expires in fifteen minutes instead of
        // renewing itself quietly for the rest of the day.
        self::assertSame([], $this->client->getResponse()->headers->getCookies());

        $this->get('/api/manager/audit?tenantId=' . $tenantId);
        $actions = array_map(static fn (array $e): string => $e['action'], $this->json()['items']);
        // Recorded before the token was minted, so a failed attempt is on record
        // too - "who tried?" is most of what a trail is asked after an incident.
        self::assertContains('manager.impersonation.started', $actions);
    }

    public function testImpersonatingSomebodyNotInThatTenantIsRefused(): void
    {
        $this->givenATenant('alpha');
        $stranger = $this->makeUser('stranger@example.test');
        $other = $this->givenATenant('beta');
        $this->givenIAmAnOperator();

        $this->post(sprintf('/api/manager/tenants/%s/users/%s/impersonate', $other, $stranger->id()));

        // The one designed crossing between realms is still a crossing into a
        // specific membership; without one there is no session to mint.
        self::assertResponseStatusCodeSame(400);
    }

    public function testTheWorkerStatusAnswersTheTwoQuestionsAnOperatorAsks(): void
    {
        $this->givenIAmAnOperator();

        $this->get('/api/manager/workers');

        self::assertResponseIsSuccessful();
        $body = $this->json();
        // How much is waiting, and how much has failed. Anything else is
        // detail; these two are what "it didn't happen" is diagnosed from.
        self::assertIsArray($body['queues']);
        self::assertIsInt($body['failed']);
    }

    public function testTheConsoleIsClosedToAnAnonymousCaller(): void
    {
        $this->get('/api/manager/tenants');

        self::assertResponseStatusCodeSame(401);
    }
}
