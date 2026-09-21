<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Module\Identity\Contract\SessionMinterInterface;
use App\Module\Identity\Entity\Membership;
use App\Module\Identity\Entity\User;
use App\Module\Manager\Entity\PlatformManager;
use App\Module\Settings\Entity\Setting;
use App\Module\Settings\Entity\SettingOverride;
use App\Module\Tenant\Entity\Tenant;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use OpenEnu\Kernel\Crypto\Encryptor;
use OpenEnu\Kernel\Doctrine\ScopeContext;
use OpenEnu\Kernel\Security\TokenAudience;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Two realms, one signing key - and why that is safe.
 *
 * The headline test here is `testATenantTokenSharingAManagersAddressIsRefused`.
 * Two Symfony firewalls with two providers LOOK isolated and are not: they
 * verify against the same key, so the only thing separating an operator from a
 * customer would be which table happens to resolve the identifier. The `aud`
 * claim is what actually closes it (ADR-0007), and the fixture below shares an
 * email address on purpose to prove it.
 */
final class RealmIsolationTest extends WebTestCase
{
    private const string SHARED_EMAIL = 'chris@example.com';
    private const string PASSWORD = 'correct-horse-battery-staple';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private string $tenantA;
    private string $tenantB;
    private User $userA;
    private PlatformManager $operator;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();

        $container = self::getContainer();
        $em = $container->get('doctrine.orm.entity_manager');
        \assert($em instanceof EntityManagerInterface);
        $this->em = $em;

        $scope = $container->get(ScopeContext::class);
        \assert($scope instanceof ScopeContext);

        $scope->runUnscoped('test fixture reset', function (): void {
            foreach ([SettingOverride::class, Setting::class, Membership::class, User::class,
                      PlatformManager::class, Tenant::class] as $entity) {
                $this->em->createQuery('DELETE FROM ' . $entity . ' e')->execute();
            }
        });

        $this->tenantA = $this->makeTenant('alpha');
        $this->tenantB = $this->makeTenant('beta');

        $encryptor = $container->get(Encryptor::class);
        \assert($encryptor instanceof Encryptor);
        $hasher = $container->get(UserPasswordHasherInterface::class);
        \assert($hasher instanceof UserPasswordHasherInterface);

        // A tenant user and a platform operator with THE SAME ADDRESS. This is
        // the realistic attack: an operator whose personal account is also a
        // customer, or an attacker who signs up using an address they have seen
        // in a "contact support" footer.
        $this->userA = new User(self::SHARED_EMAIL, $encryptor->hashForLookup(self::SHARED_EMAIL));
        $this->userA->setPassword($hasher->hashPassword($this->userA, self::PASSWORD));
        $this->userA->verify();
        new Membership($this->userA, $this->tenantA, Membership::ROLE_OWNER);
        $this->em->persist($this->userA);

        $this->operator = new PlatformManager(self::SHARED_EMAIL, $encryptor->hashForLookup(self::SHARED_EMAIL));
        $this->operator->setPassword($hasher->hashPassword($this->operator, self::PASSWORD));
        $this->em->persist($this->operator);

        $this->em->flush();
    }

    private function makeTenant(string $slug): string
    {
        $tenant = new Tenant($slug, ucfirst($slug));
        $tenant->activate();
        $this->em->persist($tenant);
        $this->em->flush();

        return (string) $tenant->id();
    }

    private function jwt(): JWTTokenManagerInterface
    {
        $jwt = self::getContainer()->get(JWTTokenManagerInterface::class);
        \assert($jwt instanceof JWTTokenManagerInterface);

        return $jwt;
    }

    private function tenantToken(): string
    {
        $membership = $this->userA->membershipIn($this->tenantA);
        \assert($membership !== null);

        return $this->jwt()->createFromPayload($this->userA, [
            TokenAudience::CLAIM => TokenAudience::App->value,
            'tid' => $this->tenantA,
            'role' => $membership->role(),
            'roles' => $membership->securityRoles(),
        ]);
    }

    private function managerToken(): string
    {
        return $this->jwt()->createFromPayload($this->operator, [
            TokenAudience::CLAIM => TokenAudience::Manager->value,
            'roles' => $this->operator->getRoles(),
        ]);
    }

    private function get(string $path, string $token): int
    {
        $this->client->request('GET', $path, server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);

        return $this->client->getResponse()->getStatusCode();
    }

    // ── the headline ────────────────────────────────────────────────────────

    public function testATenantTokenSharingAManagersAddressIsRefused(): void
    {
        // Both identities exist with the same email. Without the `aud` claim
        // this token would verify (same key) and then resolve against the
        // manager provider, and a customer would BE an operator.
        self::assertSame(
            401,
            $this->get('/api/manager/tenants', $this->tenantToken()),
            'A tenant token must never authenticate on the operator realm, even when '
            . 'an operator exists with the same address.',
        );
    }

    public function testAManagerTokenIsRefusedOnTheTenantRealm(): void
    {
        // And the other direction: an operator token must not become a customer
        // session, or an operator could act as a tenant without the audit trail
        // recording an impersonation.
        self::assertSame(401, $this->get('/api/projects', $this->managerToken()));
    }

    public function testEachTokenWorksOnItsOwnRealm(): void
    {
        // The rules must not make the system unusable: both tokens work where
        // they belong. A check that refuses everything proves nothing.
        self::assertSame(200, $this->get('/api/manager/tenants', $this->managerToken()));
        self::assertSame(200, $this->get('/api/projects', $this->tenantToken()));
    }

    public function testAnUnsignedOrForgedAudienceDoesNotHelp(): void
    {
        // `aud` is inside the signature, so editing it invalidates the token
        // rather than changing its realm.
        $forged = $this->tenantToken();
        $parts = explode('.', $forged);
        $payload = json_decode(base64_decode(strtr($parts[1], '-_', '+/')) ?: '{}', true);
        \assert(\is_array($payload));
        $payload[TokenAudience::CLAIM] = TokenAudience::Manager->value;
        $parts[1] = rtrim(strtr(base64_encode(json_encode($payload, \JSON_THROW_ON_ERROR)), '+/', '-_'), '=');

        self::assertSame(401, $this->get('/api/manager/tenants', implode('.', $parts)));
    }

    // ── impersonation ───────────────────────────────────────────────────────

    public function testAnImpersonationTokenActsInTheTenantRealmAndIsMarked(): void
    {
        $session = $this->mintImpersonation();

        // It is a genuine `aud: app` token - it passes the audience check
        // honestly rather than being an exception to it (ADR-0008).
        self::assertSame(200, $this->get('/api/projects', $session['token']));
        self::assertSame($this->tenantA, $session['tenantId']);
        self::assertSame(900, $session['expiresIn'], 'A support session is minutes, not a working day.');
    }

    public function testAnImpersonationTokenIsRefusedOnAGuardedAction(): void
    {
        $session = $this->mintImpersonation();

        $this->client->request(
            'PATCH',
            '/api/projects/0192f000-0000-7000-8000-000000000000',
            server: [
                'HTTP_AUTHORIZATION' => 'Bearer ' . $session['token'],
                'CONTENT_TYPE' => 'application/json',
            ],
            content: json_encode(['name' => 'support did this'], \JSON_THROW_ON_ERROR),
        );

        // 403 and not 404: the operator can see the button, so hiding it would
        // only confuse. What they must not get is the action.
        self::assertSame(403, $this->client->getResponse()->getStatusCode());
    }

    public function testAnImpersonationSessionCannotRenewItself(): void
    {
        // Not refreshable, by construction: nothing is issued to renew with, so
        // a support session expires rather than quietly continuing for a day.
        $session = $this->mintImpersonation();

        self::assertArrayNotHasKey('cookie', $session, 'Minting must not produce a refresh credential.');

        // And using it issues none either - the token alone cannot bootstrap a
        // longer-lived session.
        $this->get('/api/projects', $session['token']);
        self::assertSame([], $this->client->getResponse()->headers->getCookies());

        // Refreshing without a cookie is simply unauthenticated, so there is no
        // path from an impersonation token back to an ordinary one.
        $this->client->request('POST', '/api/auth/refresh');
        self::assertSame(401, $this->client->getResponse()->getStatusCode());
    }

    public function testImpersonationRequiresMembershipOfThatTenant(): void
    {
        $minter = self::getContainer()->get(SessionMinterInterface::class);
        \assert($minter instanceof SessionMinterInterface);

        // The user belongs to tenant A. Asking for a session in tenant B must
        // fail, or an operator could mint access to a tenant nobody is in.
        $this->expectException(\RuntimeException::class);
        $minter->mintImpersonationSession(
            (string) $this->userA->id(),
            $this->tenantB,
            ['sub' => (string) $this->operator->id(), 'realm' => 'manager'],
        );
    }

    /** @return array{token: string, expiresIn: int, tenantId: string, viewingAs: array<string, mixed>} */
    private function mintImpersonation(): array
    {
        $minter = self::getContainer()->get(SessionMinterInterface::class);
        \assert($minter instanceof SessionMinterInterface);

        return $minter->mintImpersonationSession(
            (string) $this->userA->id(),
            $this->tenantA,
            ['sub' => (string) $this->operator->id(), 'realm' => 'manager'],
        );
    }

    // ── kill switches ───────────────────────────────────────────────────────

    public function testAFlagFlippedForOneTenantDoesNotAffectAnother(): void
    {
        // The operational property: turning a feature off for one customer at
        // 3am, without a deploy and without touching anyone else.
        $setting = new Setting('example.archive', 'Bulk archive', Setting::TYPE_BOOL, true);
        $this->em->persist($setting);
        $this->em->flush();

        $tokenA = $this->tenantToken();

        // On by default, for everyone.
        self::assertSame(202, $this->post('/api/projects/archive', $tokenA), (string) $this->client->getResponse()->getContent());

        $this->client->request(
            'PUT',
            '/api/manager/tenants/' . $this->tenantA . '/settings/example.archive',
            server: [
                'HTTP_AUTHORIZATION' => 'Bearer ' . $this->managerToken(),
                'CONTENT_TYPE' => 'application/json',
            ],
            content: json_encode(['value' => false], \JSON_THROW_ON_ERROR),
        );
        self::assertResponseIsSuccessful();

        // Takes effect on the very NEXT request, not when a cache entry happens
        // to expire - which is the whole point of a kill switch. The handler
        // invalidates by tag for exactly this reason.
        self::assertSame(
            404,
            $this->post('/api/projects/archive', $tokenA),
            'A disabled flag makes the route 404, not 403. Body: ' . $this->client->getResponse()->getContent(),
        );

        // And tenant B, which nobody touched, is unaffected.
        self::assertSame(202, $this->post('/api/projects/archive', $this->tokenForTenantB()));
    }

    private function tokenForTenantB(): string
    {
        $container = self::getContainer();
        $encryptor = $container->get(Encryptor::class);
        \assert($encryptor instanceof Encryptor);
        $hasher = $container->get(UserPasswordHasherInterface::class);
        \assert($hasher instanceof UserPasswordHasherInterface);

        $email = 'beta-owner@example.com';
        $user = new User($email, $encryptor->hashForLookup($email));
        $user->setPassword($hasher->hashPassword($user, self::PASSWORD));
        $user->verify();
        $membership = new Membership($user, $this->tenantB, Membership::ROLE_OWNER);
        $this->em->persist($user);
        $this->em->flush();

        return $this->jwt()->createFromPayload($user, [
            TokenAudience::CLAIM => TokenAudience::App->value,
            'tid' => $this->tenantB,
            'role' => $membership->role(),
            'roles' => $membership->securityRoles(),
        ]);
    }

    private function post(string $path, string $token): int
    {
        $this->client->request('POST', $path, server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);

        return $this->client->getResponse()->getStatusCode();
    }
}
