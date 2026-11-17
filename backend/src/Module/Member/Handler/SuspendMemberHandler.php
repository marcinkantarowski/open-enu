<?php

declare(strict_types=1);

namespace App\Module\Member\Handler;

use App\Module\Identity\Contract\MembershipsInterface;
use App\Module\Member\Command\SuspendMember;
use App\Module\Member\Service\AccessGuard;
use Doctrine\ORM\EntityManagerInterface;
use OpenEnu\Kernel\Command\SnapshotCollector;
use OpenEnu\Kernel\Doctrine\ScopeContext;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class SuspendMemberHandler
{
    public function __construct(
        private MembershipsInterface $memberships,
        private AccessGuard $guard,
        private EntityManagerInterface $em,
        private ScopeContext $scope,
        private SnapshotCollector $snapshots,
    ) {
    }

    /** @return array<string, mixed> */
    public function __invoke(SuspendMember $command): array
    {
        $tenantId = $this->scope->tenantId() ?? throw new BadRequestHttpException('No tenant in scope.');
        $member = $this->memberships->find($tenantId, $command->membershipId) ?? throw new NotFoundHttpException('No such member.');

        $this->guard->assertManageable($tenantId, $member);
        $this->guard->assertNotLastOwner($tenantId, $member);

        $this->snapshots->before($member);
        $this->memberships->suspend($tenantId, $command->membershipId);
        $this->em->flush();

        $after = $this->memberships->find($tenantId, $command->membershipId) ?? [];
        $this->snapshots->after($after);

        return $after;
    }
}
