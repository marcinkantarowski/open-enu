<?php

declare(strict_types=1);

namespace OpenEnu\Kernel\Progress;

/**
 * Reports how far a long-running job has got.
 *
 * Every product grows an operation that takes longer than a request - an import,
 * a bulk update, a report. Without a shared way to report progress, each one
 * invents polling, and the UI for each is different.
 *
 * A handler calls `start()`, then `advance()`, then `finish()` or `fail()`.
 * Updates are published to `/tenants/{tid}/progress/{jobId}`, which `useProgress`
 * consumes.
 */
interface ProgressReporterInterface
{
    /** @return string the job id, to hand back to the caller so it can subscribe */
    public function start(string $kind, int $total, ?string $label = null): string;

    public function advance(string $jobId, int $by = 1, ?string $label = null): void;

    /** @param array<string, mixed> $result */
    public function finish(string $jobId, array $result = []): void;

    public function fail(string $jobId, string $reason): void;
}
