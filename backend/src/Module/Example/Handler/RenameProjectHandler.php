<?php

declare(strict_types=1);

namespace App\Module\Example\Handler;

use App\Module\Example\Command\RenameProject;
use App\Module\Example\Repository\ProjectRepository;
use Doctrine\ORM\EntityManagerInterface;
use OpenEnu\Kernel\Command\SnapshotCollector;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class RenameProjectHandler
{
    public function __construct(
        private ProjectRepository $projects,
        private EntityManagerInterface $em,
        private SnapshotCollector $snapshots,
    ) {
    }

    /** @return array<string, mixed> */
    public function __invoke(RenameProject $command): array
    {
        $project = $this->projects->get($command->id) ?? throw new NotFoundHttpException('No such project.');

        // Captured while the old state is still loaded. The audit middleware
        // picks these up; nothing else has to be told about them.
        $this->snapshots->before($project->toArray());

        $project->rename($command->name);
        $this->em->flush();

        $after = $project->toArray();
        $this->snapshots->after($after);

        return $after;
    }
}
