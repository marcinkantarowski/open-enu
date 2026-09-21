<?php

declare(strict_types=1);

namespace App\Module\Notification\Service;

use App\Module\Notification\Repository\NotificationRepository;
use Doctrine\ORM\EntityManagerInterface;
use OpenEnu\Kernel\Attribute\InfrastructureWrite;
use OpenEnu\Kernel\Doctrine\ScopeContext;
use OpenEnu\Kernel\Gdpr\GdprSubjectInterface;

/**
 * What this module holds about a person, and how it forgets them.
 *
 * Not optional: an arch test fails the build for any module with user-linked
 * entities that does not implement this, because an export silently missing a
 * module is a wrong answer to a legal request. It caught this module on the
 * first run after the entity was written.
 *
 * Erasure is deletion. A notification is addressed to one person and means
 * nothing without them, so there is no anonymised form worth keeping.
 */
final readonly class NotificationGdprSubject implements GdprSubjectInterface
{
    public function __construct(
        private NotificationRepository $notifications,
        private EntityManagerInterface $em,
        private ScopeContext $scope,
    ) {
    }

    public function exportFor(string $userId): iterable
    {
        // Across every tenant the person belongs to: a subject access request is
        // about the person, not about one workspace they happen to be in.
        yield 'notifications' => $this->scope->runUnscoped(
            'a subject access request covers every tenant the person belongs to',
            fn (): array => array_map(
                static fn (object $n): array => $n->toArray(),
                // A high limit rather than the feed's default: an export is not
                // a screen, and truncating it would answer the request wrongly.
                $this->notifications->feedFor($userId, 10_000),
            ),
        );
    }

    #[InfrastructureWrite(reason: 'GdprWalker audits the erasure as a whole; a command per module would fragment one legal action into many')]
    public function eraseFor(string $userId): int
    {
        return $this->scope->runUnscoped(
            'erasing a person from every tenant they belong to',
            function () use ($userId): int {
                $removed = 0;

                foreach ($this->notifications->feedFor($userId, 10_000) as $notification) {
                    $this->em->remove($notification);
                    ++$removed;
                }

                $this->em->flush();

                return $removed;
            },
        );
    }
}
