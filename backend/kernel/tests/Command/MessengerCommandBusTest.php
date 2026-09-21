<?php

declare(strict_types=1);

namespace OpenEnu\Kernel\Tests\Command;

use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use OpenEnu\Kernel\Command\CommandInterface;
use OpenEnu\Kernel\Command\MessengerCommandBus;
use OpenEnu\Kernel\Command\NullActorProvider;
use OpenEnu\Kernel\Command\SnapshotCollector;
use OpenEnu\Kernel\Contract\AuditEntry;
use OpenEnu\Kernel\Doctrine\ScopeContext;
use OpenEnu\Kernel\Tests\Support\RecordingAuditLogger;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

/**
 * ADR-0017: audit coverage is structural, not remembered.
 *
 * A handler cannot forget to audit, because it is not the handler doing it -
 * which is the entire reason writes are funnelled through this class.
 */
#[CoversClass(MessengerCommandBus::class)]
final class MessengerCommandBusTest extends TestCase
{
    private RecordingAuditLogger $audit;

    protected function setUp(): void
    {
        $this->audit = new RecordingAuditLogger();
    }

    private function bus(MessageBusInterface $inner): MessengerCommandBus
    {
        return new MessengerCommandBus(
            $inner,
            $this->audit,
            new NullActorProvider(),
            new ScopeContext($this->createMock(EntityManagerInterface::class)),
            new SnapshotCollector(),
            new RequestStack(),
        );
    }

    /** A command without a subject is legitimate - a bulk action has no single id. */
    private function command(?string $subjectId = 'item-7'): CommandInterface
    {
        return new class($subjectId) implements CommandInterface {
            public function __construct(private readonly ?string $subjectId)
            {
            }

            public function auditAction(): string
            {
                return 'demo.item.update';
            }

            public function auditSubjectId(): ?string
            {
                return $this->subjectId;
            }
        };
    }

    public function testASuccessfulCommandIsAudited(): void
    {
        $inner = $this->createMock(MessageBusInterface::class);
        $inner->method('dispatch')->willReturnCallback(
            static fn (object $m): Envelope => new Envelope($m, [new HandledStamp('ok', 'handler')]),
        );

        self::assertSame('ok', $this->bus($inner)->dispatch($this->command()));

        self::assertCount(1, $this->audit->entries());
        self::assertSame('demo.item.update', $this->audit->entries()[0]->action);
        self::assertSame('item-7', $this->audit->entries()[0]->subjectId);
        self::assertTrue($this->audit->entries()[0]->succeeded);
    }

    public function testASubjectlessCommandIsStillAudited(): void
    {
        $inner = $this->createMock(MessageBusInterface::class);
        $inner->method('dispatch')->willReturnCallback(
            static fn (object $m): Envelope => new Envelope($m, [new HandledStamp(null, 'handler')]),
        );

        $this->bus($inner)->dispatch($this->command(subjectId: null));

        self::assertNull($this->audit->entries()[0]->subjectId);
        self::assertTrue($this->audit->entries()[0]->succeeded);
    }

    public function testAFailedCommandIsAlsoAudited(): void
    {
        // An audit trail that only records successes cannot answer "who tried?",
        // which is most of what it is asked after an incident.
        $inner = $this->createMock(MessageBusInterface::class);
        $inner->method('dispatch')->willThrowException(
            new HandlerFailedException(new Envelope(new \stdClass()), [new \RuntimeException('nope')]),
        );

        try {
            $this->bus($inner)->dispatch($this->command());
            self::fail('Expected the handler exception to propagate.');
        } catch (\RuntimeException $e) {
            // Messenger's wrapper is unwrapped, so the caller catches its own
            // exception rather than a framework one it never threw.
            self::assertSame('nope', $e->getMessage());
        }

        self::assertCount(1, $this->audit->entries());
        self::assertFalse($this->audit->entries()[0]->succeeded);
        self::assertStringContainsString('nope', (string) $this->audit->entries()[0]->failureReason);
    }
}
