<?php

declare(strict_types=1);

namespace App\Module\Identity\Service;

use App\Module\Identity\Entity\Membership;
use App\Module\Identity\Entity\User;
use App\Module\Identity\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use OpenEnu\Kernel\Attribute\InfrastructureWrite;
use OpenEnu\Kernel\Crypto\Encryptor;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;

/**
 * Establishing and ending a session.
 *
 * Deliberately NOT a command: issuing, rotating and revoking session tokens is
 * authentication infrastructure, not a state change worth auditing. An audit
 * entry per token rotation would bury the entries that matter - and a refresh
 * happens every fifteen minutes, per open tab.
 *
 * The security events that DO deserve a trail - verification, password reset -
 * are commands, and go through the bus.
 */
final readonly class LoginService
{
    public function __construct(
        private UserRepository $users,
        private Encryptor $encryptor,
        private UserPasswordHasherInterface $hasher,
        private SessionIssuer $sessions,
        private EntityManagerInterface $em,
    ) {
    }

    /**
     * @return array{token: string, expiresIn: int, cookie: \Symfony\Component\HttpFoundation\Cookie, tenantId: ?string, user: User}
     */
    #[InfrastructureWrite(reason: 'session issuance and a last-login stamp; auditing every login rotation would bury the trail')]
    public function authenticate(string $email, string $password, ?string $tenantId): array
    {
        $user = $this->users->byEmailHash($this->encryptor->hashForLookup($email));

        // ONE failure for "no such user", "wrong password" and "not active".
        // Distinguishing them turns this into an address oracle. The password is
        // still verified when the user is missing would be better still, but a
        // dummy hash is its own trap; the rate limiter carries that weight.
        if ($user === null
            || !$user->isActive()
            || !$this->hasher->isPasswordValid($user, $password)
        ) {
            throw new UnauthorizedHttpException('Bearer', 'Those credentials are not valid.');
        }

        $membership = $this->pickMembership($user, $tenantId);

        $user->recordLogin();
        $session = $this->sessions->issue($user, $membership);
        $this->em->flush();

        return [...$session, 'user' => $user];
    }

    #[InfrastructureWrite(reason: 'revoking session tokens on logout')]
    public function endSession(Request $request): void
    {
        try {
            $rotated = $this->sessions->rotate($request);
            $this->sessions->revokeAllFor($rotated['user']);
            $this->em->flush();
        } catch (AuthenticationException) {
            // Nothing to revoke. The cookie is cleared by the caller regardless:
            // a logout that appears to fail is worse than one that quietly works.
        }
    }

    private function pickMembership(User $user, ?string $requestedTenantId): ?Membership
    {
        if ($requestedTenantId !== null) {
            return $user->membershipIn($requestedTenantId)
                ?? throw new UnauthorizedHttpException('Bearer', 'You are not a member of that tenant.');
        }

        // Default to where they have the most authority. A user with one
        // membership never notices; one with several lands predictably.
        $best = null;
        foreach ($user->memberships() as $membership) {
            if ($membership->isActive() && ($best === null || $membership->rank() > $best->rank())) {
                $best = $membership;
            }
        }

        return $best;
    }
}
