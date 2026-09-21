<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Module\ApiKey\Entity\ApiKey;
use App\Module\Identity\Entity\Membership;
use App\Module\Identity\Entity\User;
use App\Module\Manager\Entity\PlatformManager;
use App\Module\Tenant\Entity\Tenant;
use Doctrine\ORM\EntityManagerInterface;
use OpenEnu\Kernel\Crypto\Encryptor;
use OpenEnu\Kernel\Doctrine\ScopeContext;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Uid\Uuid;

/**
 * The fixture every functional test needs: a tenant, a person in it, a session.
 *
 * Written once because it had been written four times, identically, and each
 * copy had drifted - one rebooted the kernel between requests, one forgot to
 * re-enter the scope after login, and the difference showed up as an encryption
 * error nobody could place.
 *
 * App-level rather than in a module: it is the shape of a functional test here,
 * not the property of any one feature.
 */
abstract class ApiTestCase extends WebTestCase
{
    protected const string PASSWORD = 'correct-horse-battery';

    protected KernelBrowser $client;
    protected EntityManagerInterface $em;
    protected ScopeContext $scope;
    protected string $tenantId = '';

    /**
     * Entities wiped before each test, in deletion order.
     *
     * Listed by the subclass rather than inferred: a test that quietly deleted
     * every table would be indistinguishable from one that meant to.
     *
     * @return list<class-string>
     */
    abstract protected function fixtures(): array;

    protected function setUp(): void
    {
        $this->client = self::createClient();

        // One kernel for the whole test. The client reboots between requests by
        // default, which invalidates every reference held here - including
        // ScopeContext, so a fixture written through the stale manager is
        // encrypted under a scope the next request does not have.
        $this->client->disableReboot();

        $em = self::getContainer()->get('doctrine.orm.entity_manager');
        \assert($em instanceof EntityManagerInterface);
        $this->em = $em;

        $scope = self::getContainer()->get(ScopeContext::class);
        \assert($scope instanceof ScopeContext);
        $this->scope = $scope;

        $entities = [...$this->fixtures(), Membership::class, User::class, PlatformManager::class, Tenant::class];

        $this->scope->runUnscoped('test fixture reset', function () use ($entities): void {
            foreach ($entities as $entity) {
                $this->em->createQuery('DELETE FROM ' . $entity . ' e')->execute();
            }
        });
    }

    /** Creates an active tenant and enters its scope. */
    protected function givenATenant(string $slug = 'acme'): string
    {
        $tenant = new Tenant($slug, ucfirst($slug));
        $tenant->activate();

        $this->em->persist($tenant);
        $this->em->flush();

        $this->tenantId = (string) $tenant->id();
        $this->scope->enter([ScopeContext::TENANT => $this->tenantId]);

        return $this->tenantId;
    }

    /**
     * Creates a tenant that has NOT been activated, and enters its scope.
     *
     * This is the state `POST /api/auth/register` leaves behind, and it is the
     * only starting point from which verification can be shown to do anything.
     */
    protected function givenAPendingTenant(string $slug = 'pending-co'): string
    {
        $tenant = new Tenant($slug, ucfirst($slug));

        $this->em->persist($tenant);
        $this->em->flush();

        $this->tenantId = (string) $tenant->id();
        $this->scope->enter([ScopeContext::TENANT => $this->tenantId]);

        return $this->tenantId;
    }

    /**
     * Re-reads the current tenant from Postgres, with the identity map emptied.
     *
     * The clear() is the point: an assertion made against the object already in
     * memory passes whether or not anything was written, which is exactly how a
     * silently-discarded flush stayed hidden here once.
     */
    protected function reloadTenant(): Tenant
    {
        $this->em->clear();

        $tenant = $this->em->find(Tenant::class, Uuid::fromString($this->tenantId));
        \assert($tenant instanceof Tenant);

        return $tenant;
    }

    /** Creates a verified user in the current tenant and signs the client in as them. */
    protected function givenIAmSignedIn(string $role = Membership::ROLE_OWNER, string $email = 'owner@example.test'): User
    {
        $user = $this->makeUser($email, $role);

        $this->post('/api/auth/login', ['email' => $email, 'password' => self::PASSWORD]);
        $token = $this->json()['token'] ?? null;
        \assert(\is_string($token), 'Login must succeed or the rest of the test means nothing.');

        $this->client->setServerParameter('HTTP_AUTHORIZATION', 'Bearer ' . $token);
        $this->scope->enter([ScopeContext::TENANT => $this->tenantId]);

        return $user;
    }

    /**
     * @param bool $verified pass false for an account that has not proved its
     *                       address yet - the state registration leaves behind
     */
    protected function makeUser(string $email, string $role = Membership::ROLE_OWNER, ?string $tenantId = null, bool $verified = true): User
    {
        $encryptor = self::getContainer()->get(Encryptor::class);
        \assert($encryptor instanceof Encryptor);
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        \assert($hasher instanceof UserPasswordHasherInterface);

        $user = new User($email, $encryptor->hashForLookup($email));
        $user->setPassword($hasher->hashPassword($user, self::PASSWORD));

        if ($verified) {
            $user->verify();
        }

        new Membership($user, $tenantId ?? $this->tenantId, $role);

        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    /** @param list<string> $permissions */
    protected function givenAnApiKey(array $permissions, string $secret = 'sk_functional_test_key'): string
    {
        $key = new ApiKey($this->tenantId, 'test', hash('sha256', $secret), substr($secret, 0, 12), $permissions);

        $this->em->persist($key);
        $this->em->flush();

        return $secret;
    }

    /** Creates a platform operator and signs the client in as them. */
    protected function givenIAmAnOperator(string $email = 'ops@example.test'): PlatformManager
    {
        $encryptor = self::getContainer()->get(Encryptor::class);
        \assert($encryptor instanceof Encryptor);
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        \assert($hasher instanceof UserPasswordHasherInterface);

        $manager = new PlatformManager($email, $encryptor->hashForLookup($email));
        $manager->setPassword($hasher->hashPassword($manager, self::PASSWORD));

        $this->em->persist($manager);
        $this->em->flush();

        $this->post('/api/manager/login', ['email' => $email, 'password' => self::PASSWORD]);
        $token = $this->json()['token'] ?? null;
        \assert(\is_string($token), 'Operator login must succeed.');

        $this->client->setServerParameter('HTTP_AUTHORIZATION', 'Bearer ' . $token);

        return $manager;
    }

    // ── requests ────────────────────────────────────────────────────────────

    /** @param array<string, mixed> $body */
    protected function post(string $path, array $body = []): void
    {
        $this->request('POST', $path, $body);
    }

    /** @param array<string, mixed> $body */
    protected function put(string $path, array $body = []): void
    {
        $this->request('PUT', $path, $body);
    }

    /** @param array<string, mixed> $body */
    protected function patch(string $path, array $body = [], ?string $ifMatch = null): void
    {
        $this->request('PATCH', $path, $body, $ifMatch === null ? [] : ['HTTP_IF_MATCH' => '"' . $ifMatch . '"']);
    }

    protected function get(string $path): void
    {
        $this->client->request('GET', $path);
    }

    protected function delete(string $path): void
    {
        $this->client->request('DELETE', $path);
    }

    /**
     * @param array<string, mixed> $body
     * @param array<string, string> $server
     */
    protected function request(string $method, string $path, array $body = [], array $server = []): void
    {
        $this->client->request(
            $method,
            $path,
            server: [...$server, 'CONTENT_TYPE' => 'application/json'],
            content: json_encode($body, \JSON_THROW_ON_ERROR),
        );
    }

    /** @return array<string, mixed> */
    protected function json(): array
    {
        $content = $this->client->getResponse()->getContent();
        self::assertIsString($content);

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($content, true, 512, \JSON_THROW_ON_ERROR);

        return $decoded;
    }
}
