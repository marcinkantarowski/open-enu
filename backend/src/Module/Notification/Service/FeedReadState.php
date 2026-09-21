<?php

declare(strict_types=1);

namespace App\Module\Notification\Service;

use App\Module\Notification\Repository\NotificationRepository;
use Doctrine\ORM\EntityManagerInterface;
use OpenEnu\Kernel\Attribute\InfrastructureWrite;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Marking your own feed read.
 *
 * A service and not a command, deliberately: the command bus exists to audit
 * changes somebody might later have to answer for, and "opened their
 * notifications" is not one of them. Auditing it would add a row per glance and
 * bury the trail that matters.
 *
 * The user id is always a parameter. Every method takes it, so there is no path
 * through this class that marks somebody else's notifications read.
 */
final readonly class FeedReadState
{
    public function __construct(
        private NotificationRepository $notifications,
        private EntityManagerInterface $em,
    ) {
    }

    #[InfrastructureWrite(reason: 'read state on your own feed; auditing a glance would bury the trail that matters')]
    public function markRead(string $userId, string $notificationId): void
    {
        foreach ($this->notifications->feedFor($userId) as $notification) {
            if ((string) $notification->id() === $notificationId) {
                $notification->markRead();
                $this->em->flush();

                return;
            }
        }

        // Not found *in this user's feed* - which is also the answer when it
        // belongs to somebody else. The two must be indistinguishable, or the
        // endpoint becomes a way to discover other people's notification ids.
        throw new NotFoundHttpException('No such notification.');
    }

    #[InfrastructureWrite(reason: 'read state on your own feed; auditing a glance would bury the trail that matters')]
    public function markAllRead(string $userId): void
    {
        foreach ($this->notifications->feedFor($userId) as $notification) {
            $notification->markRead();
        }

        $this->em->flush();
    }
}
