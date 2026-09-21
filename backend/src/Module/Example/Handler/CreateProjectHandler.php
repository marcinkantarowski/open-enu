<?php

declare(strict_types=1);

namespace App\Module\Example\Handler;

use App\Module\Example\Command\CreateProject;
use App\Module\Example\Entity\Project;
use App\Module\Example\Event\ProjectCreated;
use Doctrine\ORM\EntityManagerInterface;
use OpenEnu\Kernel\Command\SnapshotCollector;
use OpenEnu\Kernel\Doctrine\ScopeContext;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * The only place a project is created.
 *
 * `flush()` is legal in a `Handler/` and nowhere else outside the kernel
 * (PersistenceBoundaryRule). That rule is what makes the command bus the single
 * write path, and therefore what makes audit coverage structural rather than
 * something each author remembers.
 */
#[AsMessageHandler]
final readonly class CreateProjectHandler
{
    public function __construct(
        private EntityManagerInterface $em,
        private ScopeContext $scope,
        private SnapshotCollector $snapshots,
        private MessageBusInterface $events,
    ) {
    }

    /** @return array<string, mixed> */
    public function __invoke(CreateProject $command): array
    {
        $tenantId = $this->scope->tenantId()
            ?? throw new BadRequestHttpException('A project belongs to a tenant; none is in scope.');

        $project = new Project($command->name, $tenantId);
        $project->setDescription($command->description);
        $project->setClientReference($command->clientReference);
        $project->setAttributes($command->attributes);

        $this->em->persist($project);
        $this->em->flush();

        // No `before`: there was nothing. The audit entry shows a creation as an
        // absent before and a populated after, which is what distinguishes it
        // from an edit.
        $after = $project->toArray();
        $this->snapshots->after($after);

        // Through the outbox, so the row that queues this is written in the same
        // transaction as the project (ADR-0009).
        $this->events->dispatch(new ProjectCreated((string) $project->id(), $project->name()));

        return $after;
    }
}
