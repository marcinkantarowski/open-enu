<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Tests\Support\ApiTestCase;

/**
 * The endpoints the KERNEL owns, which belong to no module.
 *
 * App-level for that reason: putting them inside whichever module happened to
 * need them first would make them look like that module's, and the next person
 * would move them when the module moved.
 */
final class PlatformEndpointsTest extends ApiTestCase
{
    protected function fixtures(): array
    {
        return [];
    }

    public function testTheShallowHealthCheckAnswersWithoutTouchingTheDatabase(): void
    {
        $this->get('/health');

        self::assertResponseIsSuccessful();
        $body = $this->json();
        // Content, not just a status code: a crashed process usually still
        // returns *something*, and a 200 has hidden a fatal error here before.
        // See [[status-codes-alone-are-not-a-health-check]].
        self::assertSame('ok', $body['status']);
        self::assertNotEmpty($body['kernel'], 'The version is what makes a health page tell you WHICH build answered.');
    }

    public function testTheDeepCheckNamesWhatItVerified(): void
    {
        $this->get('/health/deep');

        self::assertResponseIsSuccessful();
        $body = $this->json();
        self::assertSame('ok', $body['status']);
        // Reports what it checked and what failed, rather than implying it
        // verified something it did not - the deploy gate reads this.
        self::assertSame([], $body['failed']);
        self::assertArrayHasKey('checks', $body);
    }

    public function testHealthIsReachableWithoutCredentials(): void
    {
        // Deliberately unauthenticated: Traefik and the container healthcheck
        // poll it, and neither can hold a credential.
        $this->get('/health');
        self::assertResponseIsSuccessful();
    }

    public function testARealtimeTokenSubscribesToTheCallersTenantAndNothingElse(): void
    {
        $tenantId = $this->givenATenant();
        $this->givenIAmSignedIn();

        $this->post('/api/realtime/token');

        self::assertResponseIsSuccessful();
        self::assertSame('/tenants/' . $tenantId . '/events', $this->json()['topic']);

        $cookie = $this->cookie('mercureAuthorization');
        self::assertNotNull($cookie, 'EventSource cannot set headers, so the credential has to be a cookie.');

        // The claim itself, not just the advertised topic. A `["*"]` subscribe
        // claim is the obvious thing to write and would hand every authenticated
        // user every tenant's updates - a leak no query filter can see, because
        // no query runs.
        $claims = $this->claims($cookie->getValue());
        self::assertSame([
            '/tenants/' . $tenantId . '/events',
            '/tenants/' . $tenantId . '/progress/{id}',
        ], $claims['mercure']['subscribe']);

        // Scoped to the hub's path, so it is not attached to every API request.
        self::assertSame('/.well-known/mercure', $cookie->getPath());

        // Host-only. A Domain attribute sends the token to every host beneath
        // it - with staging at stg.example.com, production's subscriber token
        // would reach the staging stack.
        self::assertNull($cookie->getDomain(), 'the realtime cookie must not cross hosts');
        self::assertTrue($cookie->isHttpOnly());
    }

    public function testARealtimeTokenIsRefusedWithoutASession(): void
    {
        $this->post('/api/realtime/token');

        // No tenant, no topic. Issuing one anyway is how a subscriber ends up
        // listening to a channel nobody checked they belong to.
        self::assertResponseStatusCodeSame(401);
    }

    private function cookie(string $name): ?\Symfony\Component\HttpFoundation\Cookie
    {
        foreach ($this->client->getResponse()->headers->getCookies() as $cookie) {
            if ($cookie->getName() === $name) {
                return $cookie;
            }
        }

        return null;
    }

    /** @return array<string, mixed> */
    private function claims(?string $jwt): array
    {
        self::assertIsString($jwt);
        $parts = explode('.', $jwt);
        self::assertCount(3, $parts, 'A Mercure token is a signed JWT.');

        $payload = base64_decode(strtr($parts[1], '-_', '+/'), true);
        self::assertIsString($payload);

        /** @var array<string, mixed> $claims */
        $claims = json_decode($payload, true, 512, \JSON_THROW_ON_ERROR);

        return $claims;
    }
}
