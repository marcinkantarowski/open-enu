<?php

declare(strict_types=1);

namespace App\Module\Identity\Security;

use App\Module\Identity\Entity\User;
use App\Module\Identity\Repository\UserRepository;
use Lexik\Bundle\JWTAuthenticationBundle\Security\User\PayloadAwareUserProviderInterface;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;

/**
 * Resolves the identifier in a JWT back to a user, carrying the token's view.
 *
 * The identifier is the UUID, not the address: the email column is encrypted, so
 * using it would put a decrypted address in every token and make authentication
 * depend on the encryption key being available at that moment.
 *
 * Being payload-aware matters. "The roles of a user" is not a well-formed
 * question here - a user is an owner in one tenant and a member in another
 * (ADR-0005) - so the roles come from the token, which knows which tenant this
 * session is for.
 *
 * @implements UserProviderInterface<User>
 */
final readonly class UserProvider implements UserProviderInterface, PayloadAwareUserProviderInterface
{
    public function __construct(private UserRepository $users)
    {
    }

    public function loadUserByIdentifier(string $identifier): UserInterface
    {
        return $this->load($identifier, []);
    }

    /** @param array<string, mixed> $payload */
    public function loadUserByIdentifierAndPayload(string $identifier, array $payload): UserInterface
    {
        return $this->load($identifier, $payload);
    }

    public function refreshUser(UserInterface $user): UserInterface
    {
        if (!$user instanceof User) {
            throw new UnsupportedUserException(sprintf('Expected %s, got %s.', User::class, $user::class));
        }

        return $this->loadUserByIdentifier($user->getUserIdentifier());
    }

    public function supportsClass(string $class): bool
    {
        return $class === User::class || is_subclass_of($class, User::class);
    }

    /** @param array<string, mixed> $payload */
    private function load(string $identifier, array $payload): User
    {
        $user = $this->users->get($identifier);

        // A disabled account is rejected here, so revoking access takes effect
        // on the next request rather than at the next login.
        if ($user === null || !$user->isActive()) {
            // Deliberately one exception for both: distinguishing them tells an
            // attacker which identifiers exist.
            throw new UserNotFoundException('No such active user.');
        }

        /** @var list<string> $roles */
        $roles = \is_array($payload['roles'] ?? null) ? array_values($payload['roles']) : ['ROLE_USER'];
        $tenantId = \is_string($payload['tid'] ?? null) ? $payload['tid'] : null;

        // Verified rather than trusted: the token says which tenant, but the
        // membership must still exist and be active. Otherwise removing someone
        // from a tenant would not take effect until their token expired.
        if ($tenantId !== null && $user->membershipIn($tenantId) === null) {
            throw new UserNotFoundException('Membership no longer valid.');
        }

        $user->withSessionRoles($roles, $tenantId);

        return $user;
    }
}
