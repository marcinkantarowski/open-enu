<?php

declare(strict_types=1);

namespace App\Module\Member\Handler;

use App\Module\Identity\Contract\MembershipsInterface;
use App\Module\Member\Command\InviteMember;
use App\Module\Member\Service\AccessGuard;
use Doctrine\ORM\EntityManagerInterface;
use OpenEnu\Kernel\Command\SnapshotCollector;
use OpenEnu\Kernel\Doctrine\ScopeContext;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class InviteMemberHandler
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
    public function __invoke(InviteMember $command): array
    {
        $tenantId = $this->scope->tenantId()
            ?? throw new BadRequestHttpException('An invitation is to a tenant; none is in scope.');

        $this->guard->assertGrantable($tenantId, $command->role, $command->unitIds);

        $invitation = $this->memberships->invite($tenantId, $command->email, $command->role, $command->unitIds);
        foreach ($invitation['unsaved'] as $row) {
            $this->em->persist($row);
        }
        $this->em->flush();

        // After the flush: a failed write never mails a link to nothing.
        $this->memberships->mailInvitation($tenantId, $command->email, $invitation['token']);

        $after = $this->memberships->find($tenantId, $invitation['membershipId']) ?? [];
        $this->snapshots->after($after);

        return $after;
    }
}
