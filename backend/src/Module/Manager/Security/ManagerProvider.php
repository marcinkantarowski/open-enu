<?php

declare(strict_types=1);

namespace App\Module\Manager\Security;

use App\Module\Manager\Entity\PlatformManager;
use App\Module\Manager\Repository\PlatformManagerRepository;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;

/**
 * Resolves operator identifiers, and only operator identifiers.
 *
 * This provider is why the realms can be separated at all - but on its own it
 * is NOT enough. Both firewalls verify against the same signing key, so a tenant
 * token presented here would pass verification and then be looked up by its
 * subject. The `aud` claim is what actually closes that (ADR-0007); this just
 * makes sure the lookup hits the right table.
 *
 * @implements UserProviderInterface<PlatformManager>
 */
final readonly class ManagerProvider implements UserProviderInterface
{
    public function __construct(private PlatformManagerRepository $managers)
    {
    }

    public function loadUserByIdentifier(string $identifier): UserInterface
    {
        $manager = $this->managers->get($identifier);

        if ($manager === null || !$manager->isActive()) {
            // One exception for both: distinguishing them would confirm which
            // operator ids exist.
            throw new UserNotFoundException('No such active operator.');
        }

        return $manager;
    }

    public function refreshUser(UserInterface $user): UserInterface
    {
        if (!$user instanceof PlatformManager) {
            throw new UnsupportedUserException(sprintf('Expected %s, got %s.', PlatformManager::class, $user::class));
        }

        return $this->loadUserByIdentifier($user->getUserIdentifier());
    }

    public function supportsClass(string $class): bool
    {
        return $class === PlatformManager::class || is_subclass_of($class, PlatformManager::class);
    }
}
