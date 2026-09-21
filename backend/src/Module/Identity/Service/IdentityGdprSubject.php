<?php

declare(strict_types=1);

namespace App\Module\Identity\Service;

use App\Module\Identity\Entity\User;
use App\Module\Identity\Repository\RefreshTokenRepository;
use App\Module\Identity\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use OpenEnu\Kernel\Attribute\InfrastructureWrite;
use OpenEnu\Kernel\Gdpr\GdprSubjectInterface;

/**
 * What this module holds about a person, and how it forgets them.
 *
 * Not optional: an arch test fails the build for any module with user-linked
 * entities that does not implement this, because an export silently missing a
 * module is a wrong answer to a legal request.
 *
 * Erasure here is deletion rather than anonymisation. Identity holds the
 * identity - there is nothing left that is meaningful once the person is
 * removed, and the audit trail (which must survive) references them by id only.
 */
final readonly class IdentityGdprSubject implements GdprSubjectInterface
{
    public function __construct(
        private UserRepository $users,
        private RefreshTokenRepository $refreshTokens,
        private EntityManagerInterface $em,
    ) {
    }

    public function exportFor(string $userId): iterable
    {
        $user = $this->users->get($userId);
        if ($user === null) {
            return;
        }

        // Decrypted on the way out - an export the subject cannot read is not an
        // export. The ORM handles that transparently.
        yield 'account' => [$user->toArray()];

        yield 'memberships' => array_map(
            static fn (object $m): array => $m->toArray(),
            $user->memberships()->toArray(),
        );

        // Sessions are listed without their hashes: telling someone which
        // sessions exist is useful, handing over the credentials is not.
        yield 'sessions' => array_map(
            static fn (object $t): array => [
                'id' => (string) $t->id(),
                'tenantId' => $t->tenantId(),
                'expiresAt' => $t->expiresAt()->format(\DATE_ATOM),
            ],
            $this->refreshTokens->forUser($user),
        );
    }

    #[InfrastructureWrite(reason: 'GdprWalker audits the erasure as a whole; a command per module would fragment one legal action into many')]
    public function eraseFor(string $userId): int
    {
        $user = $this->users->get($userId);
        if ($user === null) {
            return 0;
        }

        // Memberships, refresh tokens and security tokens all cascade from the
        // user row, so the count is what the caller sees removed here plus what
        // the database removes for us.
        $removed = 1 + $user->memberships()->count() + \count($this->refreshTokens->forUser($user));

        $this->em->remove($user);
        $this->em->flush();

        return $removed;
    }
}
