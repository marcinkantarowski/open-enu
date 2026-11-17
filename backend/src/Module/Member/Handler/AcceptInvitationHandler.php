<?php

declare(strict_types=1);

namespace App\Module\Member\Handler;

use App\Module\Identity\Contract\MembershipsInterface;
use App\Module\Member\Command\AcceptInvitation;
use Doctrine\ORM\EntityManagerInterface;
use OpenEnu\Kernel\Command\SnapshotCollector;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class AcceptInvitationHandler
{
    public function __construct(
        private MembershipsInterface $memberships,
        private EntityManagerInterface $em,
        private SnapshotCollector $snapshots,
    ) {
    }

    /** @return array{tenantId: string, membershipId: string, email: string} */
    public function __invoke(AcceptInvitation $command): array
    {
        $accepted = $this->memberships->acceptInvitation($command->token, $command->password, $command->displayName);
        $this->em->flush();

        $this->snapshots->after(['membershipId' => $accepted['membershipId'], 'tenantId' => $accepted['tenantId'], 'status' => 'active']);

        return $accepted;
    }
}
