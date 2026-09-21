<?php

declare(strict_types=1);

namespace App\Module\Example\Handler;

use App\Module\Example\Command\ArchiveProjects;
use App\Module\Example\Message\ArchiveProjectsJob;
use App\Module\Example\Repository\ProjectRepository;
use OpenEnu\Kernel\Command\SnapshotCollector;
use OpenEnu\Kernel\Progress\ProgressReporterInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Starts the work; does not do it.
 *
 * The split the kernel's CommandBusInterface describes: a command is synchronous
 * because the caller needs to know whether the *request* was accepted, and the
 * work itself goes to the jobs transport because the caller should not wait on
 * it. The progress job is opened here so the response can hand back an id the
 * browser subscribes to immediately - before any work has happened.
 */
#[AsMessageHandler]
final readonly class ArchiveProjectsHandler
{
    public function __construct(
        private ProjectRepository $projects,
        private ProgressReporterInterface $progress,
        private MessageBusInterface $jobs,
        private SnapshotCollector $snapshots,
    ) {
    }

    /** @return array{jobId: string, total: int} */
    public function __invoke(ArchiveProjects $command): array
    {
        $total = \count($this->projects->active());
        $jobId = $this->progress->start('example.archive', $total, 'Archiving projects');

        // Dispatched inside a handler, so `dispatch_after_current_bus` holds it
        // until this transaction has committed - the worker cannot start on a
        // state that then rolls back.
        $this->jobs->dispatch(new ArchiveProjectsJob($jobId));

        $result = ['jobId' => $jobId, 'total' => $total];
        // The audit entry for a bulk request is only useful if it names the work
        // it started.
        $this->snapshots->after($result);

        return $result;
    }
}
