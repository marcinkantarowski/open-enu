<?php

declare(strict_types=1);

namespace App\Module\Unit\Handler;

use App\Module\Unit\Command\CreateUnit;
use App\Module\Unit\Entity\Unit;
use App\Module\Unit\Repository\UnitRepository;
use Doctrine\ORM\EntityManagerInterface;
use OpenEnu\Kernel\Command\SnapshotCollector;
use OpenEnu\Kernel\Doctrine\ScopeContext;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class CreateUnitHandler
{
    public function __construct(
        private UnitRepository $units,
        private EntityManagerInterface $em,
        private ScopeContext $scope,
        private SnapshotCollector $snapshots,
    ) {
    }

    /** @return array<string, mixed> */
    public function __invoke(CreateUnit $command): array
    {
        $tenantId = $this->scope->tenantId()
            ?? throw new BadRequestHttpException('A unit belongs to a tenant; none is in scope.');

        $parent = null;
        if ($command->parentId !== null) {
            $parent = $this->units->get($command->parentId) ?? throw new BadRequestHttpException('No such parent unit.');
            if ($parent->isArchived()) {
                throw new BadRequestHttpException('An archived unit takes no new units.');
            }
        }

        $unit = new Unit($tenantId, $command->name, $command->kind, $parent);
        $this->em->persist($unit);
        $this->em->flush();

        $after = $unit->toArray();
        $this->snapshots->after($after);

        return $after;
    }
}
