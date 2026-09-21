<?php

declare(strict_types=1);

namespace App\Module\Notification\Service;

use App\Module\Identity\Contract\UserDirectoryInterface;
use App\Module\Notification\Entity\Notification;
use Doctrine\ORM\EntityManagerInterface;
use OpenEnu\Kernel\Attribute\InfrastructureWrite;
use OpenEnu\Kernel\Doctrine\ScopeContext;
use OpenEnu\Kernel\Notification\NotificationDefinition;
use OpenEnu\Kernel\Notification\NotifierInterface;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;

/**
 * The real notifier: a row in the feed, and mail when the type asks for it.
 *
 * Decorates the kernel's logging implementation, so every `NotifierInterface`
 * call written before this module existed starts producing real notifications
 * with no call site changing (.ai/platform/PLAN.md §6.10).
 *
 * An unknown type is dropped with a log line rather than stored. A feed entry
 * whose type has no declaration cannot be rendered - there is no title key to
 * translate - so storing it would create a row that is permanently unreadable.
 */
#[AsDecorator(decorates: 'OpenEnu\Kernel\Notification\LoggingNotifier')]
final readonly class PersistentNotifier implements NotifierInterface
{
    public function __construct(
        private NotifierInterface $inner,
        private NotificationCatalogue $catalogue,
        private UserDirectoryInterface $users,
        private NotificationMailer $mailer,
        private EntityManagerInterface $em,
        private ScopeContext $scope,
    ) {
    }

    public function toUser(string $userId, string $type, array $context = []): void
    {
        $this->inner->toUser($userId, $type, $context);

        $definition = $this->catalogue->find($type);
        $tenantId = $this->scope->tenantId();

        if ($definition === null || $tenantId === null) {
            return;
        }

        $this->store($tenantId, $userId, $definition, $context);
    }

    public function toTenant(string $tenantId, string $type, array $context = []): void
    {
        $this->inner->toTenant($tenantId, $type, $context);

        $definition = $this->catalogue->find($type);

        if ($definition === null) {
            return;
        }

        // Fanned out here rather than by the caller: which people count as "the
        // workspace" is this module's problem, and a module raising a
        // notification should not have to answer it.
        foreach ($this->users->membersOf($tenantId) as $member) {
            $this->store($tenantId, (string) $member['id'], $definition, $context, (string) ($member['email'] ?? ''));
        }
    }

    /** @param array<string, scalar|null> $context */
    #[InfrastructureWrite(reason: 'a notification is a side effect of an already-audited change, not a change of its own')]
    private function store(
        string $tenantId,
        string $userId,
        NotificationDefinition $definition,
        array $context,
        string $email = '',
    ): void {
        $this->em->persist(new Notification($tenantId, $userId, $definition->type, $context));
        $this->em->flush();

        if ($definition->sendsEmail() && $email !== '') {
            $this->mailer->send($email, $definition, $context);
        }
    }
}
