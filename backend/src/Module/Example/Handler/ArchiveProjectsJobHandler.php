<?php

declare(strict_types=1);

namespace App\Module\Example\Handler;

use App\Module\Example\Message\ArchiveProjectsJob;
use App\Module\Example\Repository\ProjectRepository;
use Doctrine\ORM\EntityManagerInterface;
use OpenEnu\Kernel\Progress\ProgressReporterInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * The work, in a worker.
 *
 * This is the "start a long job, watch it" pattern in full: advance once per
 * unit so the browser sees movement, flush once at the end so the whole archive
 * is one transaction, and report a failure rather than letting the message die
 * silently in the retry queue.
 *
 * It runs with no request. The tenant scope arrived as a stamp, which is the
 * only reason `active()` returns this tenant's rows and not zero of them.
 */
#[AsMessageHandler]
final readonly class ArchiveProjectsJobHandler
{
    public function __construct(
        private ProjectRepository $projects,
        private EntityManagerInterface $em,
        private ProgressReporterInterface $progress,
    ) {
    }

    public function __invoke(ArchiveProjectsJob $job): void
    {
        try {
            $archived = 0;

            foreach ($this->projects->active() as $project) {
                $project->archive();
                $this->progress->advance($job->jobId, 1, $project->name());
                ++$archived;
            }

            $this->em->flush();
            $this->progress->finish($job->jobId, ['archived' => $archived]);
        } catch (\Throwable $e) {
            // Marked failed before rethrowing: the retry may well succeed, but a
            // job that goes quiet is indistinguishable from one still running,
            // and somebody is watching this bar.
            $this->progress->fail($job->jobId, $e->getMessage());

            throw $e;
        }
    }
}
