<?php

declare(strict_types=1);

namespace App\Tests\Search;

use App\Module\Example\Entity\Project;
use App\Module\Tenant\Entity\Tenant;
use Doctrine\ORM\EntityManagerInterface;
use OpenEnu\Kernel\Contract\TenantScopedInterface;
use OpenEnu\Kernel\Doctrine\ScopeContext;
use OpenEnu\Kernel\Search\SearchCatalogue;
use OpenEnu\Kernel\Search\SearchIndexerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Search, as a property of the system rather than of one module.
 *
 * Deliberately app-level, like the security suite: what is under test is the
 * chain - a module's `search.php` declaration, the write-through listener, the
 * indexer's SQL and the tenant predicate inside it. Testing it inside `Example`
 * would only prove `Example`.
 */
final class SearchTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private ScopeContext $scope;
    private SearchIndexerInterface $search;
    private string $tenantA;
    private string $tenantB;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        $em = $container->get('doctrine.orm.entity_manager');
        \assert($em instanceof EntityManagerInterface);
        $this->em = $em;

        $scope = $container->get(ScopeContext::class);
        \assert($scope instanceof ScopeContext);
        $this->scope = $scope;

        $search = $container->get(SearchIndexerInterface::class);
        \assert($search instanceof SearchIndexerInterface);
        $this->search = $search;

        $this->scope->runUnscoped('test fixture reset', function (): void {
            $this->em->createQuery('DELETE FROM ' . Project::class . ' p')->execute();
            $this->em->createQuery('DELETE FROM ' . Tenant::class . ' t')->execute();
            $this->em->getConnection()->executeStatement('DELETE FROM search_index');
        });

        $this->tenantA = $this->makeTenant('search-a');
        $this->tenantB = $this->makeTenant('search-b');
    }

    public function testAProjectIsFoundByAWordInItsDescription(): void
    {
        $this->asTenant($this->tenantA, function (): void {
            $this->makeProject('Harbour refit', 'Replacing the pontoon decking and the mooring cleats.');
        });

        $this->asTenant($this->tenantA, function (): void {
            $hits = $this->search->search('pontoon');

            self::assertCount(1, $hits);
            self::assertSame(Project::class, $hits[0]->entityType);
            // The excerpt is what makes a result readable, and it is also why
            // the endpoint filters by permission before querying.
            self::assertStringContainsString('pontoon', (string) $hits[0]->excerpt);
        });
    }

    public function testAnotherTenantsProjectIsNotFound(): void
    {
        $this->asTenant($this->tenantB, function (): void {
            $this->makeProject('Harbour refit', 'Replacing the pontoon decking.');
        });

        $this->asTenant($this->tenantA, function (): void {
            // The index is one table for every tenant, and the predicate that
            // separates them is hand-written SQL the Doctrine filter cannot see.
            // Miss it and search returns every tenant's records - with excerpts.
            self::assertSame([], $this->search->search('pontoon'));
        });
    }

    public function testAnEncryptedColumnIsNotIndexed(): void
    {
        $this->asTenant($this->tenantA, function (): void {
            $project = $this->makeProject('Harbour refit', 'Nothing secret here.');
            $project->setClientReference('ACME-SECRET-0007');
            $this->em->flush();
        });

        $this->asTenant($this->tenantA, function (): void {
            // The index stores plaintext and hands back excerpts of it, so an
            // indexed encrypted column would move the secret into a table with
            // no access control of its own. `search.php` must never list one.
            self::assertSame([], $this->search->search('ACME-SECRET-0007'));

            $stored = $this->em->getConnection()->fetchOne('SELECT content FROM search_index LIMIT 1');
            self::assertStringNotContainsString('ACME-SECRET-0007', (string) $stored);
        });
    }

    public function testRemovingAProjectRemovesItFromTheIndex(): void
    {
        $this->asTenant($this->tenantA, function (): void {
            $project = $this->makeProject('Harbour refit', 'Replacing the pontoon decking.');
            $this->em->remove($project);
            $this->em->flush();

            // An index entry outliving its record is a search result that 404s.
            self::assertSame([], $this->search->search('pontoon'));
        });
    }

    public function testEveryDeclaredEntityIsIndexableAndEveryFieldExists(): void
    {
        $catalogue = self::getContainer()->get(SearchCatalogue::class);
        \assert($catalogue instanceof SearchCatalogue);

        foreach ($catalogue->all() as $class => $declaration) {
            $reflection = new \ReflectionClass($class);

            // The index is partitioned by tenant, so an unscoped entity has no
            // partition to go in - the listener would skip it silently and the
            // records would simply never be findable.
            self::assertTrue(
                $reflection->implementsInterface(TenantScopedInterface::class),
                sprintf('%s is declared searchable but is not tenant-scoped.', $class),
            );

            foreach ($declaration['fields'] as $field) {
                // A typo here is silent: the listener skips a property it cannot
                // find, so the field is simply never indexed and nobody is told.
                self::assertTrue(
                    $reflection->hasProperty($field),
                    sprintf('%s declares "%s" as searchable, but has no such property.', $class, $field),
                );
            }

            self::assertNotSame('', $declaration['permission'], $class . ' must declare a permission.');
        }
    }

    private function makeTenant(string $slug): string
    {
        $tenant = new Tenant($slug, $slug);
        $tenant->activate();

        $this->em->persist($tenant);
        $this->em->flush();

        return (string) $tenant->id();
    }

    private function makeProject(string $name, string $description): Project
    {
        $project = new Project($name, $this->scope->tenantId() ?? '');
        $project->setDescription($description);

        $this->em->persist($project);
        $this->em->flush();

        return $project;
    }

    private function asTenant(string $tenantId, callable $work): void
    {
        $this->scope->enter([ScopeContext::TENANT => $tenantId]);
        $work();
        $this->scope->reset();
    }
}
