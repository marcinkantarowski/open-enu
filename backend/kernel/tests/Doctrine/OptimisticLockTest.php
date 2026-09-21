<?php

declare(strict_types=1);

namespace OpenEnu\Kernel\Tests\Doctrine;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use OpenEnu\Kernel\Contract\VersionedInterface;
use OpenEnu\Kernel\Doctrine\OptimisticLock;
use OpenEnu\Kernel\Http\Exception\ConflictException;
use Symfony\Component\HttpFoundation\Request;

/**
 * Concurrent-edit detection.
 *
 * Without it, two people editing one record is last-write-wins: the first
 * person's work disappears, nobody is told, and the report is unreproducible
 * because it depends on timing.
 */
#[CoversClass(OptimisticLock::class)]
final class OptimisticLockTest extends TestCase
{
    private function record(int $version): VersionedInterface
    {
        return new class($version) implements VersionedInterface {
            public function __construct(private readonly int $v)
            {
            }

            public function version(): int
            {
                return $this->v;
            }

            public function id(): string
            {
                return 'rec-1';
            }
        };
    }

    public function testAStaleVersionIsRefusedWithBothVersions(): void
    {
        $request = new Request();
        $request->headers->set(OptimisticLock::HEADER, '"3"');

        try {
            (new OptimisticLock())->assertCurrent($this->record(5), $request);
            self::fail('Expected a conflict.');
        } catch (ConflictException $e) {
            self::assertSame(409, $e->getStatusCode());
            // Both versions travel to the client so a conflict bar can offer a
            // real choice instead of "reload and lose your work".
            self::assertSame(3, $e->body->yourVersion);
            self::assertSame(5, $e->body->currentVersion);
            self::assertSame('rec-1', $e->body->resourceId);
        }
    }

    public function testTheCurrentVersionPasses(): void
    {
        $request = new Request();
        $request->headers->set(OptimisticLock::HEADER, '"5"');

        $this->expectNotToPerformAssertions();
        (new OptimisticLock())->assertCurrent($this->record(5), $request);
    }

    public function testAWeakEtagIsAccepted(): void
    {
        // Proxies rewrite validators as weak. Rejecting those would make the
        // whole mechanism fail intermittently, in production only.
        $request = new Request();
        $request->headers->set(OptimisticLock::HEADER, 'W/"5"');

        $this->expectNotToPerformAssertions();
        (new OptimisticLock())->assertCurrent($this->record(5), $request);
    }

    public function testAVersionInTheBodyIsAccepted(): void
    {
        $this->expectException(ConflictException::class);
        (new OptimisticLock())->assertCurrent($this->record(9), new Request(), ['version' => 2]);
    }

    public function testNoVersionSuppliedIsAllowed(): void
    {
        // A background job or internal call has no stale read to protect
        // against. What is mandatory is that PUT/PATCH entities are versioned -
        // enforced by an arch test, not here.
        $this->expectNotToPerformAssertions();
        (new OptimisticLock())->assertCurrent($this->record(5), new Request());
    }

    public function testTheConflictBodyCanCarryTheCurrentState(): void
    {
        $request = new Request();
        $request->headers->set(OptimisticLock::HEADER, '"1"');

        try {
            (new OptimisticLock())->assertCurrent(
                $this->record(2),
                $request,
                [],
                static fn (): array => ['name' => 'as it stands now'],
            );
            self::fail('Expected a conflict.');
        } catch (ConflictException $e) {
            self::assertSame(['name' => 'as it stands now'], $e->body->current);
        }
    }
}
