<?php

declare(strict_types=1);

namespace App\Module\Progress\Service;

use App\Module\Progress\Entity\ProgressJob;
use App\Module\Progress\Repository\ProgressJobRepository;
use Doctrine\ORM\EntityManagerInterface;
use OpenEnu\Kernel\Attribute\InfrastructureWrite;
use OpenEnu\Kernel\Doctrine\ScopeContext;
use OpenEnu\Kernel\Progress\ProgressReporterInterface;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\Uid\Ulid;

/**
 * Persists progress, replacing the kernel's log-only default.
 *
 * Decoration, not replacement of call sites: a handler written in Phase 2b
 * against ProgressReporterInterface now writes durable rows without changing a
 * line (.ai/platform/PLAN.md §6.10).
 *
 * Writes here are outside the command bus on purpose - progress is metadata
 * about work, not the work. Auditing every increment would bury the audit trail
 * in noise, and a rolled-back job should still show why it stopped.
 */
#[AsDecorator(decorates: 'OpenEnu\Kernel\Progress\LoggingProgressReporter')]
#[InfrastructureWrite(reason: 'progress is metadata ABOUT work, not the work; auditing every increment would bury the trail, and a rolled-back job should still show why it stopped')]
final readonly class PersistentProgressReporter implements ProgressReporterInterface
{
    public function __construct(
        private ProgressReporterInterface $inner,
        private ProgressJobRepository $jobs,
        private EntityManagerInterface $em,
        private ScopeContext $scope,
    ) {
    }

    public function start(string $kind, int $total, ?string $label = null): string
    {
        $this->inner->start($kind, $total, $label);

        $tenantId = $this->scope->tenantId();
        $id = (new Ulid())->toBase32();

        // Without a tenant there is nobody to show this to, so it stays a log
        // line rather than a row nobody can query.
        if ($tenantId === null) {
            return $id;
        }

        $this->em->persist(new ProgressJob($id, $tenantId, $kind, $total, $label));
        $this->em->flush();

        return $id;
    }

    public function advance(string $jobId, int $by = 1, ?string $label = null): void
    {
        $this->inner->advance($jobId, $by, $label);

        $job = $this->jobs->find($jobId);
        if ($job !== null) {
            $job->advance($by, $label);
            $this->em->flush();
        }
    }

    public function finish(string $jobId, array $result = []): void
    {
        $this->inner->finish($jobId, $result);

        $job = $this->jobs->find($jobId);
        if ($job !== null) {
            $job->finish($result);
            $this->em->flush();
        }
    }

    public function fail(string $jobId, string $reason): void
    {
        $this->inner->fail($jobId, $reason);

        $job = $this->jobs->find($jobId);
        if ($job !== null) {
            $job->fail($reason);
            $this->em->flush();
        }
    }
}
