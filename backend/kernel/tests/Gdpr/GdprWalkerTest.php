<?php

declare(strict_types=1);

namespace OpenEnu\Kernel\Tests\Gdpr;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use OpenEnu\Kernel\Gdpr\GdprSubjectInterface;
use OpenEnu\Kernel\Gdpr\GdprWalker;
use OpenEnu\Kernel\Tests\Support\CallOrder;
use OpenEnu\Kernel\Tests\Support\RecordingAuditLogger;

#[CoversClass(GdprWalker::class)]
final class GdprWalkerTest extends TestCase
{
    private RecordingAuditLogger $audit;

    protected function setUp(): void
    {
        $this->audit = new RecordingAuditLogger();
    }

    private function subject(string $name, CallOrder $order): GdprSubjectInterface
    {
        return new class($name, $order) implements GdprSubjectInterface {
            public function __construct(
                private readonly string $name,
                private readonly CallOrder $order,
            ) {
            }

            public function exportFor(string $userId): iterable
            {
                yield 'rows' => [['who' => $userId, 'from' => $this->name]];
            }

            public function eraseFor(string $userId): int
            {
                $this->order->record($this->name);

                return 1;
            }
        };
    }

    public function testExportGathersEverySubjectAndNamespacesTheLabels(): void
    {
        $order = new CallOrder();
        $walker = new GdprWalker(
            [$this->subject('identity', $order), $this->subject('billing', $order)],
            $this->audit,
        );

        $bundle = $walker->export('user-1');

        // Two modules may legitimately use the same label; namespacing keeps both.
        self::assertCount(2, $bundle);
    }

    public function testErasureRunsInReverseDependencyOrder(): void
    {
        // Setup order is dependency order, so erasure must be its mirror: a
        // dependent module holding references must let go before the module it
        // references disappears.
        $order = new CallOrder();
        $walker = new GdprWalker(
            [$this->subject('identity', $order), $this->subject('billing', $order)],
            $this->audit,
        );

        $walker->erase('user-1');

        self::assertSame(['billing', 'identity'], $order->calls());
    }

    public function testBothOperationsAreAudited(): void
    {
        // "We deleted your data" is a claim that has to be evidenced later.
        $order = new CallOrder();
        $walker = new GdprWalker([$this->subject('identity', $order)], $this->audit);

        $walker->export('user-1');
        $walker->erase('user-1');

        self::assertSame(['gdpr.subject.exported', 'gdpr.subject.erased'], $this->audit->actions());
    }
}
