<?php

declare(strict_types=1);

namespace App\Module\Example\Tests\Functional;

use App\Module\ApiKey\Entity\ApiKey;
use App\Module\Example\Entity\Project;
use App\Module\Example\Fixtures\ProjectFixtures;
use App\Module\Identity\Entity\Membership;
use App\Module\Identity\Entity\User;
use App\Module\Settings\Entity\Setting;
use App\Module\Settings\Entity\SettingOverride;
use App\Module\Tenant\Entity\Tenant;
use Doctrine\ORM\EntityManagerInterface;
use OpenEnu\Kernel\Crypto\Encryptor;
use OpenEnu\Kernel\Doctrine\ScopeContext;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Every route, through real HTTP against a real database.
 *
 * The read path is exercised twice - once as a person, once as an API key -
 * because they authenticate through different authenticators and vote through
 * different voters. A module that works for one and 403s for the other is the
 * normal failure here, and only this notices.
 */
final class ProjectApiTest extends WebTestCase
{
    private const string PASSWORD = 'correct-horse-battery';
    private const string KEY = ApiKey::PREFIX . 'example-functional-test-key';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private string $tenantId;

    protected function setUp(): void
    {
        $this->client = self::createClient();

        // One kernel for the whole test. By default the client reboots between
        // requests, which invalidates every service reference held here -
        // including ScopeContext, so a fixture written through the stale manager
        // is encrypted under a scope the next request does not have.
        $this->client->disableReboot();

        $em = self::getContainer()->get('doctrine.orm.entity_manager');
        \assert($em instanceof EntityManagerInterface);
        $this->em = $em;

        $scope = self::getContainer()->get(ScopeContext::class);
        \assert($scope instanceof ScopeContext);

        // Self-contained, so it passes on a clean database and in any order.
        $scope->runUnscoped('test fixture reset', function (): void {
            // Settings too: this suite creates a flag definition, and a
            // definition left behind collides with the next run on its unique
            // identifier.
            foreach ([Project::class, ApiKey::class, SettingOverride::class, Setting::class,
                      Membership::class, User::class, Tenant::class] as $entity) {
                $this->em->createQuery('DELETE FROM ' . $entity . ' e')->execute();
            }
        });

        $tenant = new Tenant('example-test', 'Example Test');
        $tenant->activate();
        $this->em->persist($tenant);
        $this->em->flush();
        $this->tenantId = (string) $tenant->id();

        // Project is tenant-scoped, so without a scope every query returns
        // nothing - which is the filter working, and would make this whole suite
        // assert on empty results.
        $scope->enter([ScopeContext::TENANT => $this->tenantId]);

        (new ProjectFixtures($this->em))->load($this->tenantId);
        $this->em->persist(new ApiKey($this->tenantId, 'reporting', hash('sha256', self::KEY), substr(self::KEY, 0, 12), ['example.view']));
        // The flag DEFINITION, off by default - what `make flags` would have
        // created from Service/ExampleFlags.php, including `tenantEditable`.
        $flag = new Setting('example.archive', 'Bulk archive', Setting::TYPE_BOOL, false);
        $flag->setTenantEditable(true);
        $this->em->persist($flag);
        $this->em->flush();

        $this->signIn();
    }

    // ── as a person ─────────────────────────────────────────────────────────

    public function testTheListReturnsTheStandardEnvelopeAndHidesTheEncryptedColumn(): void
    {
        $this->client->request('GET', '/api/projects');

        self::assertResponseIsSuccessful();
        $body = $this->json();
        self::assertSame(2, $body['meta']['total']);
        // What leaves the server is decided per endpoint, not by the entity.
        self::assertArrayNotHasKey('clientReference', $body['items'][0]);
    }

    public function testTheEncryptedColumnIsPlaintextOnTheWireAndCiphertextOnDisk(): void
    {
        $this->client->request('GET', '/api/projects/' . ProjectFixtures::ALPHA);

        self::assertResponseIsSuccessful();
        self::assertSame('ACME-0001', $this->json()['clientReference']);
        // The version the client now holds; it comes back on the next write.
        self::assertSame('"1"', $this->client->getResponse()->headers->get('ETag'));

        // Straight to the driver, bypassing the ORM's type conversion. Both
        // halves matter: decrypting transparently is useless if it was never
        // encrypted, and encrypting is useless if it cannot be read back.
        $stored = $this->em->getConnection()->fetchOne(
            'SELECT client_reference FROM example_project WHERE id = ?',
            [ProjectFixtures::ALPHA],
        );

        self::assertIsString($stored);
        self::assertStringStartsWith('v1:', $stored);
        self::assertStringNotContainsString('ACME-0001', $stored);
    }

    public function testCreatingAProjectReturns201WithItsAttributesAndVersion(): void
    {
        $this->post('/api/projects', ['name' => 'Gamma', 'attributes' => ['priority' => 'low']]);

        self::assertResponseStatusCodeSame(201);
        $body = $this->json();
        self::assertSame('Gamma', $body['name']);
        self::assertSame(['priority' => 'low'], $body['attributes']);
    }

    public function testARenameWithAStaleVersionIsRefusedWithBothVersions(): void
    {
        // The headline behaviour: without it, two people editing one record is
        // last-write-wins and the first person's change disappears silently.
        $this->patch(ProjectFixtures::ALPHA, '1', 'First writer wins');
        self::assertResponseIsSuccessful();

        $this->patch(ProjectFixtures::ALPHA, '1', 'Second writer must be refused');
        self::assertResponseStatusCodeSame(409);

        $error = $this->json()['error'];
        self::assertSame('conflict', $error['code']);
        self::assertSame(1, $error['details']['yourVersion']);
        self::assertSame(2, $error['details']['currentVersion']);
        // The saved record travels with the conflict, so the UI can offer a real
        // choice instead of "your work is gone".
        self::assertSame('First writer wins', $error['details']['current']['name']);
    }

    public function testAnArchivedProjectIsRefusedByTheVoter(): void
    {
        $project = $this->em->find(Project::class, ProjectFixtures::ALPHA);
        \assert($project instanceof Project);
        $project->archive();
        $this->em->flush();

        $this->patch(ProjectFixtures::ALPHA, '2', 'Too late');

        // The permission allows editing projects; the voter refuses THIS row.
        // Two different questions, which is why both exist.
        self::assertResponseStatusCodeSame(403);
    }

    public function testTheArchiveRouteIs404WhileItsFlagIsOffAnd202WhenOn(): void
    {
        $this->post('/api/projects/archive');
        // 404 rather than 403: "not built yet" and "switched off during an
        // incident" must look identical from outside.
        self::assertResponseStatusCodeSame(404);

        // Turned on through the real endpoint, not by writing a row: flag
        // resolution is cached, and only the command invalidates the tag. A test
        // that edited the database directly would still see the cached `false`
        // - and would have proved nothing about how a kill switch behaves.
        $this->client->request(
            'PUT',
            '/api/settings/example.archive',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['value' => true], \JSON_THROW_ON_ERROR),
        );
        self::assertResponseIsSuccessful();

        $this->post('/api/projects/archive');
        // 202 and a job id: the work is accepted, not done. The browser
        // subscribes to this before anything has happened.
        self::assertResponseStatusCodeSame(202);
        self::assertNotEmpty($this->json()['jobId']);
        self::assertSame(2, $this->json()['total']);
    }

    public function testAnUnknownProjectIsANotFoundInTheStandardErrorShape(): void
    {
        $this->client->request('GET', '/api/projects/0192f000-0000-7000-8000-0000000000ff');

        self::assertResponseStatusCodeSame(404);
        self::assertSame('not_found', $this->json()['error']['code']);
    }

    // ── as an API key ───────────────────────────────────────────────────────

    public function testAnApiKeyScopedToViewCanListButNotCreate(): void
    {
        $this->client->setServerParameter('HTTP_AUTHORIZATION', 'Bearer ' . self::KEY);

        $this->client->request('GET', '/api/projects');
        self::assertResponseIsSuccessful();
        self::assertSame(2, $this->json()['meta']['total']);

        // The key was granted `example.view` and nothing else. A credential that
        // can do more than it was scoped to is the whole risk of having them.
        $this->post('/api/projects', ['name' => 'Nope']);
        self::assertResponseStatusCodeSame(403);
    }

    // ── helpers ─────────────────────────────────────────────────────────────

    private function signIn(): void
    {
        $container = self::getContainer();
        $encryptor = $container->get(Encryptor::class);
        \assert($encryptor instanceof Encryptor);
        $hasher = $container->get(UserPasswordHasherInterface::class);
        \assert($hasher instanceof UserPasswordHasherInterface);

        $email = 'example-owner@example.test';
        $user = new User($email, $encryptor->hashForLookup($email));
        $user->setPassword($hasher->hashPassword($user, self::PASSWORD));
        $user->verify();
        new Membership($user, $this->tenantId, Membership::ROLE_OWNER);

        $this->em->persist($user);
        $this->em->flush();

        $this->post('/api/auth/login', ['email' => $email, 'password' => self::PASSWORD]);

        $token = $this->json()['token'] ?? null;
        \assert(\is_string($token), 'Login must succeed for this suite to mean anything.');
        $this->client->setServerParameter('HTTP_AUTHORIZATION', 'Bearer ' . $token);

        // Logging in reset the scope the fixtures rely on.
        $scope = self::getContainer()->get(ScopeContext::class);
        \assert($scope instanceof ScopeContext);
        $scope->enter([ScopeContext::TENANT => $this->tenantId]);
    }

    /** @param array<string, mixed> $payload */
    private function post(string $path, array $payload = []): void
    {
        $this->client->request(
            'POST',
            $path,
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode($payload, \JSON_THROW_ON_ERROR),
        );
    }

    private function patch(string $id, string $version, string $name): void
    {
        $this->client->request(
            'PATCH',
            '/api/projects/' . $id,
            server: ['HTTP_IF_MATCH' => '"' . $version . '"', 'CONTENT_TYPE' => 'application/json'],
            content: json_encode(['name' => $name], \JSON_THROW_ON_ERROR),
        );
    }

    /** @return array<string, mixed> */
    private function json(): array
    {
        $content = $this->client->getResponse()->getContent();
        self::assertIsString($content);

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($content, true, 512, \JSON_THROW_ON_ERROR);

        return $decoded;
    }
}
