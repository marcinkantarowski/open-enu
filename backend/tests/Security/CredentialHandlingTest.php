<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Module\ApiKey\Entity\ApiKey;
use App\Module\Identity\Entity\Membership;
use App\Module\Identity\Entity\SecurityToken;
use App\Module\Identity\Entity\User;
use App\Module\Identity\Service\SecurityTokenIssuer;
use App\Module\Identity\Service\SessionIssuer;
use App\Module\Tenant\Entity\Tenant;
use Doctrine\ORM\EntityManagerInterface;
use OpenEnu\Kernel\Crypto\Encryptor;
use OpenEnu\Kernel\Doctrine\ScopeContext;
use OpenEnu\Kernel\Gdpr\GdprWalker;
use OpenEnu\Kernel\Storage\StorageInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * How credentials and personal data are stored, presented and destroyed.
 *
 * Each of these has a specific failure it prevents, named in the test. They are
 * the ones that are invisible in review: a password reset link that still works
 * after use looks exactly like one that does not.
 */
final class CredentialHandlingTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private string $tenantId;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $container = self::getContainer();

        $em = $container->get('doctrine.orm.entity_manager');
        \assert($em instanceof EntityManagerInterface);
        $this->em = $em;

        $scope = $container->get(ScopeContext::class);
        \assert($scope instanceof ScopeContext);

        $scope->runUnscoped('test fixture reset', function (): void {
            foreach ([ApiKey::class, SecurityToken::class, Membership::class, User::class, Tenant::class] as $entity) {
                $this->em->createQuery('DELETE FROM ' . $entity . ' e')->execute();
            }
        });

        $tenant = new Tenant('acme', 'Acme');
        $tenant->activate();
        $this->em->persist($tenant);
        $this->em->flush();
        $this->tenantId = (string) $tenant->id();
    }

    private function makeUser(string $email = 'ada@example.com', string $password = 'correct-horse-battery'): User
    {
        $container = self::getContainer();
        $encryptor = $container->get(Encryptor::class);
        \assert($encryptor instanceof Encryptor);
        $hasher = $container->get(UserPasswordHasherInterface::class);
        \assert($hasher instanceof UserPasswordHasherInterface);

        $user = new User($email, $encryptor->hashForLookup($email));
        $user->setPassword($hasher->hashPassword($user, $password));
        $user->verify();
        new Membership($user, $this->tenantId, Membership::ROLE_OWNER);

        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    /** @param array<string, mixed> $body */
    private function post(string $path, array $body): void
    {
        $this->client->request(
            'POST',
            $path,
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode($body, \JSON_THROW_ON_ERROR),
        );
    }

    // ── storage of credentials ──────────────────────────────────────────────

    public function testTheEmailIsCiphertextAtRestAndLoginStillWorks(): void
    {
        $this->makeUser();

        $stored = $this->em->getConnection()->fetchAssociative('SELECT email, email_hash FROM identity_user LIMIT 1');
        self::assertIsArray($stored);
        self::assertStringStartsWith('v1:', (string) $stored['email'], 'The address must be encrypted at rest.');
        self::assertStringNotContainsString('ada@example.com', (string) $stored['email']);

        // And the hash is what makes it findable - an encrypted column cannot be
        // queried, so without the sibling hash there is no login at all.
        $this->post('/api/auth/login', ['email' => 'ada@example.com', 'password' => 'correct-horse-battery']);
        self::assertResponseIsSuccessful($this->client->getResponse()->getContent() ?: 'no body');
    }

    public function testTheRefreshTokenIsHttpOnlyAndNeverInTheBody(): void
    {
        $this->makeUser();
        $this->post('/api/auth/login', ['email' => 'ada@example.com', 'password' => 'correct-horse-battery']);

        $cookie = null;
        foreach ($this->client->getResponse()->headers->getCookies() as $candidate) {
            if ($candidate->getName() === SessionIssuer::COOKIE) {
                $cookie = $candidate;
            }
        }

        self::assertNotNull($cookie, 'Login must set a refresh cookie.');
        // The whole point of ADR-0006: an XSS in any dependency can act as the
        // user while the page is open, but cannot steal a durable credential.
        self::assertTrue($cookie->isHttpOnly(), 'JavaScript must not be able to read the refresh token.');
        self::assertTrue($cookie->isSecure());
        self::assertSame('strict', $cookie->getSameSite());

        $body = (string) $this->client->getResponse()->getContent();
        self::assertStringNotContainsString($cookie->getValue(), $body, 'The refresh token must never appear in the body.');
    }

    public function testCredentialFailuresAreIndistinguishable(): void
    {
        $this->makeUser();

        // Different answers for "no such user" and "wrong password" turn this
        // endpoint into an address oracle.
        $this->post('/api/auth/login', ['email' => 'nobody@example.com', 'password' => 'correct-horse-battery']);
        $unknown = $this->client->getResponse()->getStatusCode();

        $this->post('/api/auth/login', ['email' => 'ada@example.com', 'password' => 'wrong-password-entirely']);
        $wrong = $this->client->getResponse()->getStatusCode();

        self::assertSame(401, $unknown);
        self::assertSame($unknown, $wrong);
    }

    public function testForgotPasswordAnswersTheSameForUnknownAddresses(): void
    {
        $this->makeUser();

        $this->post('/api/auth/forgot-password', ['email' => 'ada@example.com']);
        $known = (string) $this->client->getResponse()->getContent();

        $this->post('/api/auth/forgot-password', ['email' => 'nobody@example.com']);
        $unknown = (string) $this->client->getResponse()->getContent();

        self::assertSame($known, $unknown, 'Otherwise this endpoint enumerates users for anyone who asks.');
    }

    // ── single-use tokens ───────────────────────────────────────────────────

    public function testASecurityTokenIsStoredHashedAndWorksOnlyOnce(): void
    {
        $user = $this->makeUser();
        $issuer = self::getContainer()->get(SecurityTokenIssuer::class);
        \assert($issuer instanceof SecurityTokenIssuer);

        [$token, $raw] = $issuer->issue(SecurityToken::PURPOSE_RESET_PASSWORD, $user);
        $this->em->persist($token);
        $this->em->flush();

        // The raw value exists only in the email. A database leak - or a backup,
        // or a support query - must not hand out working password resets.
        $stored = $this->em->getConnection()->fetchOne('SELECT token_hash FROM identity_security_token LIMIT 1');
        self::assertIsString($stored);
        self::assertNotSame($raw, $stored);
        self::assertSame(hash('sha256', $raw), $stored);

        self::assertNotNull($issuer->redeem($raw, SecurityToken::PURPOSE_RESET_PASSWORD));
        $this->em->flush();

        // Replay: a token in a forwarded email or a browser history is dead.
        self::assertNull(
            $issuer->redeem($raw, SecurityToken::PURPOSE_RESET_PASSWORD),
            'A redeemed token must not work a second time.',
        );
    }

    public function testATokenIsUselessForADifferentPurpose(): void
    {
        // Otherwise a long-lived invitation could be redeemed as a password
        // reset, turning a 7-day token into account takeover.
        $user = $this->makeUser();
        $issuer = self::getContainer()->get(SecurityTokenIssuer::class);
        \assert($issuer instanceof SecurityTokenIssuer);

        [$token, $raw] = $issuer->issue(SecurityToken::PURPOSE_INVITATION, $user);
        $this->em->persist($token);
        $this->em->flush();

        self::assertNull($issuer->redeem($raw, SecurityToken::PURPOSE_RESET_PASSWORD));
    }

    public function testResettingAPasswordRevokesEveryExistingSession(): void
    {
        $user = $this->makeUser();
        $issuer = self::getContainer()->get(SecurityTokenIssuer::class);
        \assert($issuer instanceof SecurityTokenIssuer);

        $this->post('/api/auth/login', ['email' => 'ada@example.com', 'password' => 'correct-horse-battery']);
        self::assertResponseIsSuccessful();

        [$token, $raw] = $issuer->issue(SecurityToken::PURPOSE_RESET_PASSWORD, $user);
        $this->em->persist($token);
        $this->em->flush();

        $this->post('/api/auth/reset-password', ['token' => $raw, 'password' => 'a-brand-new-passphrase']);
        self::assertResponseIsSuccessful();

        // A reset prompted by a suspected compromise must not leave the intruder
        // logged in.
        $live = $this->em->getConnection()->fetchOne('SELECT count(*) FROM identity_refresh_token WHERE revoked = false');
        self::assertSame(0, (int) $live, 'Every session must die with the password.');
    }

    // ── API keys ────────────────────────────────────────────────────────────

    public function testAnApiKeyIsStoredHashedWithOnlyItsPrefixVisible(): void
    {
        $key = new ApiKey($this->tenantId, 'reporting', hash('sha256', 'sk_secret'), 'sk_secret12', ['example.view']);
        $this->em->persist($key);
        $this->em->flush();

        $row = $this->em->getConnection()->fetchAssociative('SELECT key_hash, key_prefix FROM api_key LIMIT 1');
        self::assertIsArray($row);
        self::assertSame(hash('sha256', 'sk_secret'), $row['key_hash']);

        // The listing shows the prefix so a key is recognisable, and never the
        // key - which exists in clear exactly once, at creation.
        self::assertArrayNotHasKey('secret', $key->toArray());
        self::assertSame('sk_secret12', $key->toArray()['prefix']);
    }

    public function testAKeyGrantsOnlyWhatItWasIssuedWith(): void
    {
        // The point of a subset: an integration created by an owner must not
        // inherit the owner's authority.
        $key = new ApiKey($this->tenantId, 'reporting', hash('sha256', 'x'), 'sk_x', ['example.view']);

        self::assertTrue($key->grants('example.view'));
        self::assertFalse($key->grants('example.manage'));
        self::assertFalse($key->grants('api_key.manage'));
    }

    public function testARevokedOrExpiredKeyIsUnusable(): void
    {
        $revoked = new ApiKey($this->tenantId, 'old', hash('sha256', 'a'), 'sk_a', ['example.view']);
        $revoked->revoke();
        self::assertFalse($revoked->isUsable());

        $expired = new ApiKey($this->tenantId, 'lapsed', hash('sha256', 'b'), 'sk_b', ['example.view']);
        $expired->setExpiresAt(new \DateTimeImmutable('-1 day'));
        self::assertFalse($expired->isUsable());
    }

    // ── storage ─────────────────────────────────────────────────────────────

    public function testAnUploadLandsUnderItsOwnTenantAndItsUrlExpires(): void
    {
        $container = self::getContainer();
        $scope = $container->get(ScopeContext::class);
        \assert($scope instanceof ScopeContext);
        $storage = $container->get(StorageInterface::class);
        \assert($storage instanceof StorageInterface);

        $scope->enter([ScopeContext::TENANT => $this->tenantId]);

        $key = $storage->write('attachment', '../../etc/passwd', 'file contents', 'text/plain');

        // The tenant prefix is what makes cross-tenant access a path traversal
        // rather than an off-by-one, and a whole tenant's files deletable as one
        // prefix.
        self::assertStringStartsWith('tenants/' . $this->tenantId . '/attachment/', $key);

        // A user-supplied filename never becomes a path: the key is generated.
        self::assertStringNotContainsString('..', $key);
        self::assertStringNotContainsString('passwd', $key);

        $url = $storage->temporaryUrl($key, 300);
        self::assertStringContainsString('/storage/', $url);
        // A storage URL that works forever is a credential, and it will end up
        // in a chat message.
        self::assertMatchesRegularExpression('/[?&]_expiration=\d+/', $url, 'The URL must carry an expiry.');
        self::assertMatchesRegularExpression('/[?&]_hash=/', $url, 'And a signature over it.');

        $storage->delete($key);
    }

    // ── GDPR ────────────────────────────────────────────────────────────────

    public function testAPersonsDataCanBeExportedAndErased(): void
    {
        $user = $this->makeUser();
        $userId = (string) $user->id();

        $walker = self::getContainer()->get(GdprWalker::class);
        \assert($walker instanceof GdprWalker);

        $bundle = $walker->export($userId);
        self::assertNotEmpty($bundle, 'An export that returns nothing is a wrong answer to a legal request.');

        $flattened = json_encode($bundle);
        self::assertIsString($flattened);
        // Decrypted on the way out - an export the subject cannot read is not
        // an export.
        self::assertStringContainsString('ada@example.com', $flattened);

        $walker->erase($userId);
        $this->em->clear();

        self::assertSame(
            0,
            (int) $this->em->getConnection()->fetchOne('SELECT count(*) FROM identity_user WHERE id = ?', [$userId]),
        );
        self::assertSame(
            0,
            (int) $this->em->getConnection()->fetchOne('SELECT count(*) FROM identity_membership WHERE user_id = ?', [$userId]),
            'Erasure must cascade; leaving orphaned rows means the person is still identifiable.',
        );
    }
}
