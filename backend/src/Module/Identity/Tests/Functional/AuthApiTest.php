<?php

declare(strict_types=1);

namespace App\Module\Identity\Tests\Functional;

use App\Module\Identity\Entity\Membership;
use App\Module\Identity\Entity\RefreshToken;
use App\Module\Identity\Entity\SecurityToken;
use App\Module\Identity\Service\SecurityTokenIssuer;
use App\Tests\Support\ApiTestCase;
use OpenEnu\Kernel\Doctrine\ScopeContext;

/**
 * Everything that establishes or ends a session, through real HTTP.
 *
 * These are the endpoints reachable without credentials, so they are also the
 * ones where a mistake is reachable without credentials.
 */
final class AuthApiTest extends ApiTestCase
{
    protected function fixtures(): array
    {
        return [RefreshToken::class, SecurityToken::class];
    }

    public function testRegistrationCreatesAnUnverifiedAccountAndAnswers202(): void
    {
        $this->post('/api/auth/register', [
            'email' => 'new@example.test',
            'password' => 'a-long-enough-password',
            'tenantName' => 'New Workspace',
        ]);

        // 202, not 201: the account exists and is unusable until the address is
        // proved, and "created" would invite the client to try logging in.
        self::assertResponseStatusCodeSame(202);
        self::assertSame('unverified', $this->json()['status']);
    }

    public function testAShortPasswordIsRefused(): void
    {
        $this->post('/api/auth/register', [
            'email' => 'short@example.test',
            'password' => 'tooshort',
            'tenantName' => 'Nope',
        ]);

        self::assertResponseStatusCodeSame(400);
    }

    public function testVerificationActivatesTheAccountAndIsSingleUse(): void
    {
        // Starts from the state registration actually leaves behind: a PENDING
        // tenant and an UNVERIFIED user. Starting from an active tenant and a
        // verified user would assert nothing - both of the writes this endpoint
        // exists to make would already be done.
        $this->givenAPendingTenant();
        $token = $this->issueVerificationToken();

        $this->post('/api/auth/verify', ['token' => $token]);
        self::assertResponseIsSuccessful();
        self::assertSame('verified', $this->json()['status']);

        // Read back from the database, with the identity map emptied first.
        // Every assertion here once passed against objects in memory while
        // nothing whatsoever reached Postgres - the handler mutated entities
        // that a `runUnscoped()` inside tenant activation had already detached.
        // See [[unflushed-work-does-not-survive-rununscoped]].
        self::assertTrue(
            $this->reloadTenant()->isActive(),
            'Verification must activate the tenant created at signup.',
        );

        // The account is usable now, which is the only thing the person who
        // clicked the link cares about.
        $this->post('/api/auth/login', ['email' => 'pending@example.test', 'password' => self::PASSWORD]);
        self::assertResponseIsSuccessful();
        self::assertArrayHasKey('token', $this->json());

        // Replaying it must fail. A verification link that keeps working is a
        // link somebody else can use from a forwarded email.
        $this->post('/api/auth/verify', ['token' => $token]);
        self::assertResponseStatusCodeSame(400);
    }

    public function testAnUnknownVerificationTokenLooksIdenticalToAUsedOne(): void
    {
        $this->post('/api/auth/verify', ['token' => 'not-a-real-token']);

        // One message for expired, unknown and already-used: distinguishing
        // them tells an attacker which links were real.
        self::assertResponseStatusCodeSame(400);
    }

    public function testSwitchingTenantReturnsASessionScopedToTheOtherWorkspace(): void
    {
        $first = $this->givenATenant('alpha');
        $user = $this->givenIAmSignedIn();

        $second = $this->givenATenant('beta');
        new Membership($user, $second, Membership::ROLE_MEMBER);
        $this->em->flush();

        $this->scope->enter([ScopeContext::TENANT => $first]);
        $this->post('/api/auth/switch-tenant', ['tenantId' => $second]);

        self::assertResponseIsSuccessful();
        $body = $this->json();
        self::assertSame($second, $body['tenantId']);
        // The role travels with the workspace, not with the person (ADR-0005).
        self::assertSame('member', $body['role']);
    }

    public function testSwitchingToAWorkspaceYouAreNotInIsRefused(): void
    {
        $this->givenATenant('alpha');
        $this->givenIAmSignedIn();
        $stranger = $this->givenATenant('somebody-elses');
        $this->scope->enter([ScopeContext::TENANT => $this->tenantId]);

        $this->post('/api/auth/switch-tenant', ['tenantId' => $stranger]);

        self::assertResponseStatusCodeSame(401);
    }

    public function testLogoutClearsTheRefreshCookie(): void
    {
        $this->givenATenant();
        $this->givenIAmSignedIn();

        $this->post('/api/auth/logout');

        self::assertResponseIsSuccessful();
        $cookie = $this->client->getResponse()->headers->getCookies()[0] ?? null;
        self::assertNotNull($cookie, 'Logout must send a cookie - an expired one.');
        // Cleared whatever the server thought: a logout that appears to fail
        // leaves somebody believing they are signed out when they are not.
        self::assertLessThan(time(), $cookie->getExpiresTime());
    }

    public function testForgotPasswordAnswersTheSameForAKnownAndUnknownAddress(): void
    {
        $this->givenATenant();
        $this->makeUser('known@example.test');

        $this->post('/api/auth/forgot-password', ['email' => 'known@example.test']);
        $known = $this->client->getResponse()->getStatusCode();

        $this->post('/api/auth/forgot-password', ['email' => 'nobody@example.test']);
        $unknown = $this->client->getResponse()->getStatusCode();

        // Identical, deliberately. A form that behaves differently for a known
        // address turns password reset into a way to test whether somebody has
        // an account here.
        self::assertSame($known, $unknown);
        self::assertSame(200, $known);
    }

    public function testResettingWithAnInvalidTokenIsRefused(): void
    {
        $this->post('/api/auth/reset-password', ['token' => 'nope', 'password' => 'a-long-enough-password']);

        self::assertResponseStatusCodeSame(400);
    }

    // ── the profile ─────────────────────────────────────────────────────────

    public function testTheProfileIsReadableWithoutAGrant(): void
    {
        $this->givenATenant();
        $this->givenIAmSignedIn(Membership::ROLE_MEMBER, 'member@example.test');

        $this->get('/api/profile');

        // No permission check: it is the caller's own record, and requiring a
        // grant to read yourself locks people out of their own settings.
        self::assertResponseIsSuccessful();
        self::assertSame('member@example.test', $this->json()['email']);
    }

    public function testTheProfileAcceptsANameAndASupportedLocale(): void
    {
        $this->givenATenant();
        $this->givenIAmSignedIn();

        $this->patch('/api/profile', ['displayName' => 'Ada', 'locale' => 'pl']);

        self::assertResponseIsSuccessful();
        self::assertSame('Ada', $this->json()['displayName']);
        self::assertSame('pl', $this->json()['locale']);
    }

    public function testAnUnsupportedLocaleIsRefusedRatherThanIgnored(): void
    {
        $this->givenATenant();
        $this->givenIAmSignedIn();

        $this->patch('/api/profile', ['locale' => 'fr']);

        // Rejected, not silently dropped: a setting that "saves" and then does
        // not apply is a bug report nobody can reproduce.
        self::assertResponseStatusCodeSame(400);
    }

    /**
     * Mints a real verification token the way registration does.
     *
     * Through the issuer rather than by hand: the token is stored HASHED, so a
     * test that inserted a row would be testing its own idea of the hash.
     */
    private function issueVerificationToken(): string
    {
        $user = $this->makeUser('pending@example.test', verified: false);

        $issuer = self::getContainer()->get(SecurityTokenIssuer::class);
        \assert($issuer instanceof SecurityTokenIssuer);

        [$token, $raw] = $issuer->issue(
            SecurityToken::PURPOSE_VERIFY_EMAIL,
            $user,
            ['tenantId' => $this->tenantId],
            $this->tenantId,
        );

        $this->em->persist($token);
        $this->em->flush();

        return $raw;
    }

}
