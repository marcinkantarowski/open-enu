<?php

declare(strict_types=1);

namespace App\Module\Unit\Handler;

use App\Module\Unit\Command\ArchiveUnit;
use App\Module\Unit\Repository\UnitRepository;
use Doctrine\ORM\EntityManagerInterface;
use OpenEnu\Kernel\Command\SnapshotCollector;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Archives one unit and everything below it. Their rows stay visible to the
 * people scoped to them - archiving hides a unit from pickers, nothing more.
 */
#[AsMessageHandler]
final readonly class ArchiveUnitHandler
{
    public function __construct(
        private UnitRepository $units,
        private EntityManagerInterface $em,
        private SnapshotCollector $snapshots,
    ) {
    }

    /** @return array<string, mixed> */
    public function __invoke(ArchiveUnit $command): array
    {
        $unit = $this->units->get($command->id) ?? throw new NotFoundHttpException('No such unit.');

        $this->snapshots->before($unit->toArray());

        foreach ([$unit, ...$this->units->below($unit)] as $archived) {
            $archived->archive();
        }

        $this->em->flush();

        $after = $unit->toArray();
        $this->snapshots->after($after);

        return $after;
    }
}
