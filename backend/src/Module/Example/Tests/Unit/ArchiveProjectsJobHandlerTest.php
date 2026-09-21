<?php

declare(strict_types=1);

namespace App\Module\Example\Tests\Unit;

use App\Module\Example\Entity\Project;
use App\Module\Example\Handler\ArchiveProjectsJobHandler;
use App\Module\Example\Message\ArchiveProjectsJob;
use App\Module\Example\Repository\ProjectRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use OpenEnu\Kernel\Progress\ProgressReporterInterface;
use Symfony\Component\Uid\Uuid;

/**
 * The job, in isolation.
 *
 * The collaborators are doubled here, and that is the right call for once: what
 * is under test is the handler's *sequence* - advance per project, flush once,
 * report a failure rather than going quiet - not any rule those collaborators
 * enforce. The moment a test depends on what Doctrine does with the entity, it
 * belongs in the functional suite instead.
 */
final class ArchiveProjectsJobHandlerTest extends TestCase
{
    public function testEveryActiveProjectIsArchivedAndReportedOneByOne(): void
    {
        $projects = [$this->project('Alpha'), $this->project('Beta')];
        $progress = $this->createMock(ProgressReporterInterface::class);

        $advanced = [];
        $progress->method('advance')->willReturnCallback(
            static function (string $jobId, int $by, ?string $label) use (&$advanced): void {
                $advanced[] = $label;
            },
        );
        // One per project, so a watching browser sees movement rather than a bar
        // that jumps from 0 to 100 once everything is already done.
        $progress->expects(self::once())->method('finish')->with('job-1', ['archived' => 2]);
        $progress->expects(self::never())->method('fail');

        $em = $this->createMock(EntityManagerInterface::class);
        // Once, at the end: the whole archive is one transaction, so a failure
        // halfway leaves no half-archived tenant.
        $em->expects(self::once())->method('flush');

        (new ArchiveProjectsJobHandler($this->repository($projects), $em, $progress))(new ArchiveProjectsJob('job-1'));

        self::assertTrue($projects[0]->isArchived());
        self::assertTrue($projects[1]->isArchived());
        self::assertSame(['Alpha', 'Beta'], $advanced);
    }

    public function testAFailureIsReportedBeforeItIsRethrown(): void
    {
        $progress = $this->createMock(ProgressReporterInterface::class);
        // Without this the job simply goes quiet, and a job that has gone quiet
        // is indistinguishable from one still running to the person watching it.
        $progress->expects(self::once())->method('fail')->with('job-2', 'the database went away');
        $progress->expects(self::never())->method('finish');

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('flush')->willThrowException(new \RuntimeException('the database went away'));

        $handler = new ArchiveProjectsJobHandler($this->repository([$this->project('Alpha')]), $em, $progress);

        // Rethrown, so Messenger can retry it.
        $this->expectException(\RuntimeException::class);
        $handler(new ArchiveProjectsJob('job-2'));
    }

    private function project(string $name): Project
    {
        return new Project($name, (string) Uuid::v7());
    }

    /** @param list<Project> $projects */
    private function repository(array $projects): ProjectRepository
    {
        $repository = $this->createMock(ProjectRepository::class);
        $repository->method('active')->willReturn($projects);

        return $repository;
    }
}
