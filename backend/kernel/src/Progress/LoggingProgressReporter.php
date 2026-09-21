<?php

declare(strict_types=1);

namespace OpenEnu\Kernel\Progress;

use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Ulid;

/**
 * Progress to the log, before the Progress module exists.
 *
 * Deliberately usable rather than a no-op: a handler written now can report
 * progress, and its output is visible in `make logs`. The Progress module
 * (Phase 3) decorates this to persist jobs and publish to Mercure, and no
 * handler changes.
 */
final readonly class LoggingProgressReporter implements ProgressReporterInterface
{
    public function __construct(private LoggerInterface $logger)
    {
    }

    public function start(string $kind, int $total, ?string $label = null): string
    {
        $jobId = (new Ulid())->toBase32();
        $this->logger->info('progress.start', ['job' => $jobId, 'kind' => $kind, 'total' => $total, 'label' => $label]);

        return $jobId;
    }

    public function advance(string $jobId, int $by = 1, ?string $label = null): void
    {
        $this->logger->debug('progress.advance', ['job' => $jobId, 'by' => $by, 'label' => $label]);
    }

    public function finish(string $jobId, array $result = []): void
    {
        $this->logger->info('progress.finish', ['job' => $jobId, 'result' => $result]);
    }

    public function fail(string $jobId, string $reason): void
    {
        $this->logger->error('progress.fail', ['job' => $jobId, 'reason' => $reason]);
    }
}
