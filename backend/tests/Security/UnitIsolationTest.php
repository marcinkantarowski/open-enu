<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Module\Example\Entity\Project;
use App\Module\Tenant\Entity\Tenant;
use App\Module\Unit\Command\MoveUnit;
use App\Module\Unit\Contract\UnitTreeInterface;
use App\Module\Unit\Entity\Unit;
use Doctrine\ORM\EntityManagerInterface;
use OpenEnu\Kernel\Command\CommandBusInterface;
use OpenEnu\Kernel\Doctrine\ScopeContext;
use OpenEnu\Kernel\Messenger\TenantScopeMiddleware;
use OpenEnu\Kernel\Messenger\TenantStamp;
use OpenEnu\Kernel\Search\SearchIndexerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;

/**
 * Isolation inside a tenant (ADR-0025), attacked from the same directions as
 * TenantIsolationTest: a read, a write, a worker message, the search index -
 * and a tree that changes under a live scope.
 *
 * The tree: north (with north-east below it) and south. One project in each,
 * and one in no unit at all - the whole tenant's.
 */
final class UnitIsolationTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private ScopeContext $scope;
    private string $tenant;
    /** @var array<string, string> name => unit id */
    private array $units = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get('doctrine.orm.entity_manager');
        $scope = self::getContainer()->get(ScopeContext::class);
        \assert($em instanceof EntityManagerInterface && $scope instanceof ScopeContext);
        [$this->em, $this->scope] = [$em, $scope];

        $this->scope->runUnscoped('test fixture reset', function (): void {
            foreach ([Project::class, Unit::class, Tenant::class] as $entity) {
                $this->em->createQuery('DELETE FROM ' . $entity . ' e')->execute();
            }
        });

        $tenant = new Tenant('units', 'Units');
        $tenant->activate();
        $this->em->persist($tenant);
        $this->em->flush();
        $this->tenant = (string) $tenant->id();
        $this->scope->enterTenant($this->tenant);

        $north = new Unit($this->tenant, 'North', 'region');
        $northEast = new Unit($this->tenant, 'North-East', 'site', $north);
        $south = new Unit($this->tenant, 'South', 'region');
        foreach (['north' => $north, 'north-east' => $northEast, 'south' => $south] as $name => $unit) {
            $this->em->persist($unit);
            $this->units[$name] = $unit->id();
        }

        foreach (['north', 'north-east', 'south', null] as $unit) {
            $project = new Project('ledger ' . ($unit ?? 'tenant'), $this->tenant);
            $project->placeIn($unit !== null ? $this->units[$unit] : null);
            $this->em->persist($project);
        }

        $this->em->flush();
        $this->em->clear();
    }

    private function narrowTo(string ...$names): void
    {
        $tree = self::getContainer()->get(UnitTreeInterface::class);
        \assert($tree instanceof UnitTreeInterface);
        $this->scope->enterTenant($this->tenant);
        $this->scope->enterUnits($this->tenant, $tree->expand(array_map(fn (string $n): string => $this->units[$n], $names)));
    }

    /** @return list<string> read from Postgres, never from the identity map */
    private function visible(): array
    {
        $this->em->clear();
        $names = array_map(static fn (Project $p): string => $p->name(), $this->em->getRepository(Project::class)->findAll());
        sort($names);

        return $names;
    }

    public function testAUnitSeesItselfAndEverythingBelowIt(): void
    {
        $this->narrowTo('north');

        self::assertSame(['ledger north', 'ledger north-east'], $this->visible());
    }

    public function testTheWholeTenantSeesEveryRowIncludingThoseInNoUnit(): void
    {
        $this->scope->enterTenant($this->tenant);

        self::assertSame(['ledger north', 'ledger north-east', 'ledger south', 'ledger tenant'], $this->visible());
    }

    public function testATenantEnteredWithoutUnitsSeesNoUnitScopedRow(): void
    {
        // The old one-dimension call: a forgotten unit set is "nothing", not "all".
        $this->scope->enter([ScopeContext::TENANT => $this->tenant]);

        self::assertSame([], $this->visible());
    }

    public function testADirectLookupOfAnotherUnitsRowFindsNothing(): void
    {
        $this->scope->enterTenant($this->tenant);
        $south = $this->em->getRepository(Project::class)->findOneBy(['name' => 'ledger south']);
        \assert($south instanceof Project);
        $id = $south->id();

        $this->narrowTo('north');
        $this->em->clear();

        self::assertNull($this->em->find(Project::class, $id));
    }

    public function testAWriteIntoAUnitOutsideTheScopeIsRefused(): void
    {
        $this->narrowTo('north');

        foreach (['south' => $this->units['south'], 'the whole tenant' => null] as $where => $unit) {
            $project = new Project('smuggled', $this->tenant);
            $project->placeIn($unit);

            try {
                // Refused on persist(), before anything reaches the unit of work.
                $this->em->persist($project);
                $this->em->flush();
                self::fail('A narrowed scope wrote into ' . $where . '.');
            } catch (AccessDeniedHttpException $e) {
                self::assertStringContainsString('outside the units', $e->getMessage());
            }

            $this->em->clear();
        }

        $this->scope->enterTenant($this->tenant);
        self::assertNotContains('smuggled', $this->visible());
    }

    public function testMovingARowIntoAUnitOutsideTheScopeIsRefused(): void
    {
        $this->narrowTo('north');
        $project = $this->em->getRepository(Project::class)->findOneBy(['name' => 'ledger north']);
        \assert($project instanceof Project);

        $project->placeIn($this->units['south']);

        $this->expectException(AccessDeniedHttpException::class);
        $this->em->flush();
    }

    public function testAWorkerMessageIsNarrowedAsItsSenderWas(): void
    {
        $this->narrowTo('north-east');
        $stamp = new TenantStamp($this->scope->all());
        $this->scope->reset();

        // What the worker's handler sees: the stack's last step lists projects.
        $seen = new \ArrayObject();
        $stack = new class ($seen, $this->visible(...)) implements StackInterface, MiddlewareInterface {
            /** @param \ArrayObject<int, string> $seen */
            public function __construct(private \ArrayObject $seen, private \Closure $visible)
            {
            }

            public function next(): MiddlewareInterface
            {
                return $this;
            }

            public function handle(Envelope $envelope, StackInterface $stack): Envelope
            {
                $this->seen->exchangeArray(($this->visible)());

                return $envelope;
            }
        };

        (new TenantScopeMiddleware($this->scope))->handle(
            new Envelope(new \stdClass(), [$stamp, new ReceivedStamp('async')]),
            $stack,
        );

        self::assertSame(['ledger north-east'], $seen->getArrayCopy());
    }

    public function testSearchFindsOnlyWhatTheScopeMaySee(): void
    {
        $search = self::getContainer()->get(SearchIndexerInterface::class);
        \assert($search instanceof SearchIndexerInterface);

        $this->narrowTo('south');
        $found = array_map(static fn ($hit): ?string => $hit->excerpt, $search->search('ledger'));

        self::assertCount(1, $found, 'An excerpt of another unit\'s record is that record, leaked.');
        self::assertStringContainsString('south', (string) $found[0]);

        $this->scope->enterTenant($this->tenant);
        self::assertCount(4, $search->search('ledger'));
    }

    public function testMovingAUnitChangesWhoSeesItsRowsAtOnce(): void
    {
        $bus = self::getContainer()->get(CommandBusInterface::class);
        \assert($bus instanceof CommandBusInterface);

        $this->scope->enterTenant($this->tenant);
        $bus->dispatch(new MoveUnit($this->units['south'], $this->units['north']));

        $this->narrowTo('north');
        self::assertSame(['ledger north', 'ledger north-east', 'ledger south'], $this->visible());
    }

    public function testAUnitCannotMoveUnderItsOwnSubtree(): void
    {
        $bus = self::getContainer()->get(CommandBusInterface::class);
        \assert($bus instanceof CommandBusInterface);
        $this->scope->enterTenant($this->tenant);

        try {
            $bus->dispatch(new MoveUnit($this->units['north'], $this->units['north-east']));
            self::fail('A unit moved under its own child - the tree now has a cycle.');
        } catch (\Throwable $e) {
            self::assertStringContainsString('cannot move under itself', $e->getMessage());
        }
    }
}
