<?php

declare(strict_types=1);

namespace App\Module\Unit\Handler;

use App\Module\Unit\Command\MoveUnit;
use App\Module\Unit\Repository\UnitRepository;
use Doctrine\ORM\EntityManagerInterface;
use OpenEnu\Kernel\Command\SnapshotCollector;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Moving a unit moves its whole subtree, and so changes at once who sees its
 * rows: someone scoped to the old parent loses them on their next request.
 */
#[AsMessageHandler]
final readonly class MoveUnitHandler
{
    public function __construct(
        private UnitRepository $units,
        private EntityManagerInterface $em,
        private SnapshotCollector $snapshots,
    ) {
    }

    /** @return array<string, mixed> */
    public function __invoke(MoveUnit $command): array
    {
        $unit = $this->units->get($command->id) ?? throw new NotFoundHttpException('No such unit.');
        $parent = null;

        if ($command->parentId !== null) {
            $parent = $this->units->get($command->parentId) ?? throw new BadRequestHttpException('No such parent unit.');
            if ($unit->contains($parent)) {
                throw new BadRequestHttpException('A unit cannot move under itself or anything below it.');
            }
        }

        $this->snapshots->before($unit->toArray());

        // Loaded before the move: `below()` matches the OLD path.
        $descendants = $this->units->below($unit);
        $oldPrefix = $unit->path();
        $unit->placeUnder($parent);

        foreach ($descendants as $descendant) {
            $descendant->rebase($oldPrefix, $unit->path());
        }

        $this->em->flush();

        $after = $unit->toArray();
        $this->snapshots->after($after);

        return $after;
    }
}
