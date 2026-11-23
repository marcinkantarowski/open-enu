<?php

declare(strict_types=1);

namespace OpenEnu\Kernel\Tests\Http;

use OpenEnu\Kernel\Http\Listener\ApiExceptionListener;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * The listener stops propagation when it answers, so Symfony's own exception
 * logger never runs after it. In production that made every 500 invisible:
 * the client got "An unexpected error occurred." and the log got nothing.
 */
#[CoversClass(ApiExceptionListener::class)]
final class ApiExceptionListenerTest extends TestCase
{
    /** @var list<array{level: string, context: array<string, mixed>}> */
    private array $logged = [];

    public function testAnUnexpectedExceptionIsHiddenFromTheClientAndLogged(): void
    {
        $event = $this->handle(new \RuntimeException('key file is not readable'));

        $response = $event->getResponse();
        self::assertNotNull($response);
        self::assertSame(500, $response->getStatusCode());
        $body = (string) $response->getContent();
        self::assertStringContainsString('An unexpected error occurred.', $body);
        self::assertStringNotContainsString('key file', $body, 'production never returns the real message');

        self::assertCount(1, $this->logged, 'a 500 the client cannot read must reach the log');
        self::assertSame('critical', $this->logged[0]['level']);
        self::assertSame('key file is not readable', $this->logged[0]['context']['message']);
        self::assertInstanceOf(\RuntimeException::class, $this->logged[0]['context']['exception']);
    }

    public function testAnExpectedAnswerIsNotLogged(): void
    {
        $event = $this->handle(new NotFoundHttpException('No such project.'));

        self::assertSame(404, $event->getResponse()?->getStatusCode());
        self::assertSame([], $this->logged, 'a 404 is an answer, not a fault');
    }

    private function handle(\Throwable $throwable): ExceptionEvent
    {
        $logger = new class extends AbstractLogger {
            /** @var list<array{level: string, context: array<string, mixed>}> */
            public array $records = [];

            /** @param array<string, mixed> $context */
            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->records[] = ['level' => (string) $level, 'context' => $context];
            }
        };

        $kernel = $this->createStub(HttpKernelInterface::class);
        $event = new ExceptionEvent($kernel, Request::create('/api/anything'), HttpKernelInterface::MAIN_REQUEST, $throwable);
        (new ApiExceptionListener(false, $logger))($event);
        $this->logged = $logger->records;

        return $event;
    }
}
