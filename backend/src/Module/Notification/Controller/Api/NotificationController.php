<?php

declare(strict_types=1);

namespace App\Module\Notification\Controller\Api;

use App\Module\Notification\Entity\Notification;
use App\Module\Notification\Repository\NotificationRepository;
use App\Module\Notification\Service\FeedReadState;
use App\Module\Notification\Service\NotificationCatalogue;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Your own feed, and nobody else's.
 *
 * The user comes from the session on every route - never from a parameter. A
 * feed endpoint that took a user id would be one missing check away from being
 * a way to read someone else's notifications.
 *
 * It reads the id through `getUserIdentifier()` rather than type-hinting
 * Identity's `User`: importing another module's entity is a build failure
 * (ADR-0002), and the identifier IS the uuid here by design.
 */
final readonly class NotificationController
{
    public function __construct(
        private NotificationRepository $notifications,
        private NotificationCatalogue $catalogue,
        private FeedReadState $readState,
        private Security $security,
    ) {
    }

    #[Route('/api/notifications', name: 'notification_index', methods: ['GET'])]
    #[IsGranted('notification.view')]
    public function index(): JsonResponse
    {
        $userId = $this->currentUserId();
        $types = $this->catalogue->all();

        return new JsonResponse([
            'items' => array_map(
                static fn (Notification $n): array => [
                    ...$n->toArray(),
                    // The keys, not rendered text: the client translates them in
                    // the reader's language, which is why the row stores
                    // arguments rather than a sentence.
                    'titleKey' => $types[$n->type()]->titleKey ?? $n->type(),
                    'bodyKey' => $types[$n->type()]->bodyKey ?? $n->type(),
                ],
                $this->notifications->feedFor($userId),
            ),
            'meta' => ['unread' => $this->notifications->unreadCountFor($userId)],
        ]);
    }

    #[Route('/api/notifications/{id}/read', name: 'notification_read', methods: ['POST'])]
    #[IsGranted('notification.view')]
    public function read(string $id): JsonResponse
    {
        $this->readState->markRead($this->currentUserId(), $id);

        return new JsonResponse(['status' => 'read']);
    }

    #[Route('/api/notifications/read-all', name: 'notification_read_all', methods: ['POST'])]
    #[IsGranted('notification.view')]
    public function readAll(): JsonResponse
    {
        $this->readState->markAllRead($this->currentUserId());

        return new JsonResponse(['status' => 'read', 'unread' => 0]);
    }

    private function currentUserId(): string
    {
        return $this->security->getUser()?->getUserIdentifier()
            ?? throw new AccessDeniedHttpException('A feed belongs to somebody.');
    }
}
