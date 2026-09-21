<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Module\Example\Entity\Project;
use App\Module\Tenant\Entity\Tenant;
use Doctrine\ORM\EntityManagerInterface;
use OpenEnu\Kernel\Doctrine\ScopeContext;
use OpenEnu\Kernel\Messenger\TenantStamp;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The property the whole system rests on: one tenant cannot see another's data.
 *
 * Every other guarantee here is worth less than this one. These tests exercise
 * the paths where isolation is most easily lost - an unscoped request, a worker
 * message with no stamp, a direct id lookup - because those are the ones that
 * look correct in review.
 */
final class TenantIsolationTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private ScopeContext $scope;
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

        $this->scope->runUnscoped('test fixture reset', function (): void {
            $this->em->createQuery('DELETE FROM ' . Project::class . ' d')->execute();
            $this->em->createQuery('DELETE FROM ' . Tenant::class . ' t')->execute();
        });

        $this->tenantA = $this->makeTenant('alpha');
        $this->tenantB = $this->makeTenant('beta');

        $this->makeItem($this->tenantA, "alpha's secret");
        $this->makeItem($this->tenantB, "beta's secret");
    }

    private function makeTenant(string $slug): string
    {
        $tenant = new Tenant($slug, ucfirst($slug));
        $tenant->activate();
        $this->em->persist($tenant);
        $this->em->flush();

        return (string) $tenant->id();
    }

    private function makeItem(string $tenantId, string $name): Project
    {
        $item = new Project($name, $tenantId);
        $this->em->persist($item);
        $this->em->flush();
        $this->em->clear();

        return $item;
    }

    /** @return list<Project> */
    private function listItems(): array
    {
        return $this->em->getRepository(Project::class)->findAll();
    }

    public function testATenantSeesOnlyItsOwnRows(): void
    {
        $this->scope->enter([ScopeContext::TENANT => $this->tenantA]);
        $items = $this->listItems();

        self::assertCount(1, $items);
        self::assertSame("alpha's secret", $items[0]->name());
    }

    public function testTheOtherTenantSeesOnlyItsOwn(): void
    {
        $this->scope->enter([ScopeContext::TENANT => $this->tenantB]);
        $items = $this->listItems();

        self::assertCount(1, $items);
        self::assertSame("beta's secret", $items[0]->name());
    }

    public function testWithNoTenantInScopeNothingIsReturned(): void
    {
        // THE fail-closed property (ADR-0004). The tempting alternative -
        // omitting the predicate when the scope is unknown - returns EVERY
        // tenant's rows for an unauthenticated route or a forgetful CLI command,
        // and looks identical in a code review.
        $this->scope->reset();

        self::assertSame(
            [],
            $this->listItems(),
            'With no tenant established the filter must match nothing. Returning '
            . 'everything is the failure mode this design exists to make impossible.',
        );
    }

    public function testFetchingAnotherTenantsRowByItsExactIdFindsNothing(): void
    {
        // Knowing the id is the realistic attack: ids leak through logs, URLs
        // and support tickets. The filter applies to find() too, so the row is
        // not merely hidden from lists.
        $this->scope->enter([ScopeContext::TENANT => $this->tenantB]);
        $theirs = $this->listItems()[0];
        $theirId = $theirs->id();

        $this->em->clear();
        $this->scope->enter([ScopeContext::TENANT => $this->tenantA]);

        self::assertNull($this->em->getRepository(Project::class)->find($theirId));
    }

    public function testTheIdentityMapCannotSmuggleARowAcrossScopes(): void
    {
        // The subtlest bypass: an entity loaded unscoped stays in the identity
        // map, and a later scoped find() returns it FROM MEMORY without issuing
        // the SQL the filter would have constrained. runUnscoped() clears the
        // EntityManager on exit precisely to close this.
        $this->scope->reset();

        $all = $this->scope->runUnscoped('deliberately loading every tenant', fn (): array => $this->listItems());
        self::assertCount(2, $all, 'The unscoped read should see both tenants.');

        $this->scope->enter([ScopeContext::TENANT => $this->tenantA]);
        $visible = $this->listItems();

        self::assertCount(1, $visible, 'After runUnscoped() the identity map must not still hold the other tenant.');
        self::assertSame("alpha's secret", $visible[0]->name());
    }

    public function testAWorkerMessageCarriesItsTenantAndAnUnstampedOneSeesNothing(): void
    {
        // The single most common source of tenant leaks in async code. A handler
        // that runs unscoped does nothing to nobody's data - far better than
        // touching the wrong tenant's, and still a bug worth catching.
        $stamp = new TenantStamp([ScopeContext::TENANT => $this->tenantA]);
        self::assertSame($this->tenantA, $stamp->scope[ScopeContext::TENANT]);

        $this->scope->enter($stamp->scope);
        self::assertCount(1, $this->listItems());

        // An un-stamped message: the worker establishes nothing.
        $this->scope->reset();
        self::assertSame([], $this->listItems());
    }

    public function testRunUnscopedRequiresAReason(): void
    {
        // The reason is recorded and auditable. "Because the ORM was awkward
        // here" is a reason worth having to write down.
        $this->expectException(\InvalidArgumentException::class);
        $this->scope->runUnscoped('', static fn (): bool => true);
    }

    public function testTheScopeIsRestoredAfterAnUnscopedBlockThrows(): void
    {
        $this->scope->enter([ScopeContext::TENANT => $this->tenantA]);

        try {
            $this->scope->runUnscoped('boom', static function (): void {
                throw new \RuntimeException('handler failed');
            });
        } catch (\RuntimeException) {
            // expected
        }

        // If an exception left the filter disabled, every subsequent query in
        // that process would run unscoped - a leak with no visible cause.
        self::assertCount(1, $this->listItems(), 'The scope must be restored even when the callback throws.');
    }
}
