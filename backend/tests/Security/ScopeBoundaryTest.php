<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Module\Tenant\Entity\Tenant;
use Doctrine\ORM\EntityManagerInterface;
use OpenEnu\Kernel\Doctrine\ScopeContext;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * What may and may not cross a `runUnscoped()` boundary.
 *
 * The boundary exists for isolation: an entity loaded outside the tenant filter
 * must not stay in the identity map, or a later scoped `find()` returns it from
 * memory without ever issuing the SQL the filter would have constrained. So the
 * EntityManager is cleared on the way out, and that is not negotiable.
 *
 * The cost of that clear is what these tests pin down. It detaches the CALLER's
 * entities too, so work mutated before the crossing and flushed after it is
 * discarded - with no exception, no SQL and no log line. That shipped here
 * twice: email verification wrote nothing, and suspending a tenant suspended
 * nothing. See [[unflushed-work-does-not-survive-rununscoped]].
 *
 * Against the real EntityManager rather than a mocked UnitOfWork: the rule under
 * test IS Doctrine's behaviour when flushing a detached entity, and a mock would
 * only assert this file's idea of it.
 */
final class ScopeBoundaryTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private ScopeContext $scope;

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
            $this->em->createQuery('DELETE FROM ' . Tenant::class . ' t')->execute();
        });
    }

    public function testAPendingWriteMayNotCrossTheBoundary(): void
    {
        $tenant = $this->makeTenant();
        $tenant->activate();

        $this->expectException(\LogicException::class);
        // The message must name the entity: "something was discarded" sends the
        // reader to the wrong file, and this failure is already hard to place.
        $this->expectExceptionMessageMatches('/would discard unsaved changes to .*Tenant/');

        $this->scope->runUnscoped('a read that would silently eat the line above', fn (): int => 1);
    }

    public function testAWriteMadeInsideTheCallbackMustBeFlushedInsideIt(): void
    {
        $id = (string) $this->makeTenant()->id();

        $this->expectException(\LogicException::class);

        $this->scope->runUnscoped('mutating without flushing', function () use ($id): void {
            $tenant = $this->em->find(Tenant::class, Uuid::fromString($id));
            \assert($tenant instanceof Tenant);

            // No flush. The clear on the way out would throw this away.
            $tenant->activate();
        });
    }

    public function testAWriteFlushedInsideTheCallbackSurvives(): void
    {
        $id = (string) $this->makeTenant()->id();

        $this->scope->runUnscoped('the correct shape: load, mutate and flush inside', function () use ($id): void {
            $tenant = $this->em->find(Tenant::class, Uuid::fromString($id));
            \assert($tenant instanceof Tenant);

            $tenant->activate();
            $this->em->flush();
        });

        // Re-read rather than reusing the object: an assertion against what is
        // already in memory passes whether or not anything reached Postgres,
        // which is precisely how the original bug stayed invisible.
        $reloaded = $this->em->find(Tenant::class, Uuid::fromString($id));
        self::assertInstanceOf(Tenant::class, $reloaded);
        self::assertTrue($reloaded->isActive());
    }

    public function testACleanBoundaryIsCrossedNormally(): void
    {
        $id = (string) $this->makeTenant()->id();

        $found = $this->scope->runUnscoped(
            'a plain cross-tenant read, which is what this exists for',
            fn (): ?Tenant => $this->em->find(Tenant::class, Uuid::fromString($id)),
        );

        self::assertInstanceOf(Tenant::class, $found);
        // Detached on the way out, by design. Reading loaded state is fine;
        // writing to it is the trap the other tests describe.
        self::assertFalse($this->em->contains($found));
    }

    private function makeTenant(string $slug = 'boundary'): Tenant
    {
        $tenant = new Tenant($slug, ucfirst($slug));

        $this->em->persist($tenant);
        $this->em->flush();
        $this->em->clear();

        $reloaded = $this->em->find(Tenant::class, $tenant->id());
        \assert($reloaded instanceof Tenant);

        return $reloaded;
    }
}
