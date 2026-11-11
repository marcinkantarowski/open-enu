<?php

declare(strict_types=1);

namespace App\Module\Unit\Handler;

use App\Module\Unit\Command\RenameUnit;
use App\Module\Unit\Repository\UnitRepository;
use Doctrine\ORM\EntityManagerInterface;
use OpenEnu\Kernel\Command\SnapshotCollector;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class RenameUnitHandler
{
    public function __construct(
        private UnitRepository $units,
        private EntityManagerInterface $em,
        private SnapshotCollector $snapshots,
    ) {
    }

    /** @return array<string, mixed> */
    public function __invoke(RenameUnit $command): array
    {
        $unit = $this->units->get($command->id) ?? throw new NotFoundHttpException('No such unit.');

        $this->snapshots->before($unit->toArray());
        $unit->rename($command->name, $command->kind);
        $this->em->flush();

        $after = $unit->toArray();
        $this->snapshots->after($after);

        return $after;
    }
}
