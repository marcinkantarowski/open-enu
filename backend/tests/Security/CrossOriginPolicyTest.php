<?php

declare(strict_types=1);

namespace App\Tests\Security;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Which origins the browser is allowed to call this API from.
 *
 * The subdomain layout makes every call the apps make cross-origin, so CORS is
 * not an afterthought here - it is load-bearing, and it is the only thing
 * standing between a hostile page and an authenticated request made with the
 * user's own cookies.
 *
 * Worth testing in PHP rather than only in the browser, because the failure is
 * asymmetric: a browser test notices a policy that is too NARROW the moment the
 * app stops working, and never notices one that is too WIDE.
 */
final class CrossOriginPolicyTest extends WebTestCase
{
    /** @return list<array{string}> */
    public static function allowedOrigins(): array
    {
        // Matches APP_URL / MANAGER_URL in .env.test, which is what the CORS
        // config names directly.
        return [['https://app.test.local'], ['https://manager.test.local']];
    }

    #[DataProvider('allowedOrigins')]
    public function testTheTwoAppOriginsMayCallTheApi(string $origin): void
    {
        $client = self::createClient();

        $client->request('OPTIONS', '/api/projects', server: [
            'HTTP_ORIGIN' => $origin,
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
        ]);

        $response = $client->getResponse();

        self::assertSame($origin, $response->headers->get('Access-Control-Allow-Origin'));
        self::assertSame(
            'true',
            $response->headers->get('Access-Control-Allow-Credentials'),
            'Without this the refresh cookie never leaves the browser and every session dies on reload.',
        );
    }

    public function testAThirdOriginIsRefused(): void
    {
        $client = self::createClient();

        $client->request('OPTIONS', '/api/projects', server: [
            'HTTP_ORIGIN' => 'https://evil.example.com',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
        ]);

        // The preflight itself answers - that is normal - but WITHOUT the header
        // that would let the caller read the response. Its absence is the
        // refusal; the browser enforces it.
        self::assertNull(
            $client->getResponse()->headers->get('Access-Control-Allow-Origin'),
            'A page on any other origin must not be able to read this API with the user\'s cookies.',
        );
    }

    public function testALookalikeOriginIsRefused(): void
    {
        // The specific failure an `origin_regex` with an unescaped dot creates:
        // `app.test.local` as a pattern also matches `appXtest.local`, which
        // somebody can register. Exact matching is what makes this impossible.
        $client = self::createClient();

        $client->request('OPTIONS', '/api/projects', server: [
            'HTTP_ORIGIN' => 'https://appXtest.local',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
        ]);

        self::assertNull($client->getResponse()->headers->get('Access-Control-Allow-Origin'));
    }

    public function testTheAllowedHeadersCoverOptimisticLocking(): void
    {
        // If-Match carries the version a write is based on. Omit it from the
        // allowlist and the browser strips the header - every conflict check
        // then passes, silently, and concurrent edits go back to last-write-wins
        // with no error anywhere (ADR-0019).
        $client = self::createClient();

        $client->request('OPTIONS', '/api/projects/x', server: [
            'HTTP_ORIGIN' => 'https://app.test.local',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'PATCH',
            'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'content-type,if-match',
        ]);

        $allowed = strtolower((string) $client->getResponse()->headers->get('Access-Control-Allow-Headers'));

        self::assertStringContainsString('if-match', $allowed);
        self::assertStringContainsString('authorization', $allowed);
    }

    public function testTheClientCanReadTheVersionAndTheRequestId(): void
    {
        // Response headers are invisible to JavaScript unless exposed, however
        // present they are on the wire. ETag is how the client learns the
        // version to send back; X-Request-Id is what a user quotes when
        // reporting a problem.
        $client = self::createClient();

        $client->request('GET', '/api/projects', server: ['HTTP_ORIGIN' => 'https://app.test.local']);

        $exposed = strtolower((string) $client->getResponse()->headers->get('Access-Control-Expose-Headers'));

        self::assertStringContainsString('etag', $exposed);
        self::assertStringContainsString('x-request-id', $exposed);
    }
}
