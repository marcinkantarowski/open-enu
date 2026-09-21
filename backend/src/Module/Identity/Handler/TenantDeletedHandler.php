<?php

declare(strict_types=1);

namespace App\Module\Identity\Handler;

use App\Module\Identity\Repository\MembershipRepository;
use App\Module\Tenant\Event\TenantDeleted;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Lets go of a deleted tenant.
 *
 * This is the shape cross-module integrity takes here. There is no foreign key
 * from membership to tenant - cross-module FKs are a build failure (ADR-0002) -
 * so the Tenant module announces the deletion and every module holding its data
 * cleans up its own. The Tenant module never learns what anyone else stores,
 * which is what lets modules be added and removed freely.
 *
 * A user left with no memberships is NOT deleted: they are a person, not a
 * tenant's property, and they may be invited elsewhere tomorrow.
 *
 * It lives in Handler/ rather than Listener/ because that is what it is - a
 * Messenger handler, and therefore a legitimate write path. Listener/ is for
 * synchronous framework event listeners, which are not.
 */
#[AsMessageHandler]
final readonly class TenantDeletedHandler
{
    public function __construct(
        private MembershipRepository $memberships,
        private EntityManagerInterface $em,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(TenantDeleted $event): void
    {
        $affected = $this->memberships->forTenant($event->subjectId);

        foreach ($affected as $membership) {
            $this->em->remove($membership);
        }

        $this->em->flush();

        $this->logger->info('identity.tenant_deleted.cleanup', [
            'tenant' => $event->subjectId,
            'memberships_removed' => \count($affected),
        ]);
    }
}
