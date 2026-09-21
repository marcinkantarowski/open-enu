<?php

declare(strict_types=1);

namespace App\Module\Identity\Service;

use App\Module\Identity\Contract\SessionMinterInterface;
use App\Module\Identity\Repository\UserRepository;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use OpenEnu\Kernel\Security\Impersonation;
use OpenEnu\Kernel\Security\TokenAudience;

/**
 * Mints an impersonation session.
 *
 * Note what the token is and is not:
 *
 *  • **`aud: app`** - it really is a tenant-realm token, so it passes the
 *    audience check honestly rather than being an exception to it. That is the
 *    difference between a designed crossing and a hole.
 *  • **`imp: true` and `act`** - so the audit trail names both identities, and
 *    the guard can refuse destructive actions.
 *  • **15 minutes, no refresh cookie** - a support session expires rather than
 *    renewing itself. Nobody is impersonating a customer for a working day.
 */
final readonly class SessionMinter implements SessionMinterInterface
{
    public function __construct(
        private UserRepository $users,
        private JWTTokenManagerInterface $jwt,
        private SessionPayload $payload,
    ) {
    }

    public function mintImpersonationSession(string $userId, string $tenantId, array $actor): array
    {
        $user = $this->users->get($userId)
            ?? throw new \RuntimeException('No such user.');

        if (!$user->isActive()) {
            throw new \RuntimeException('That account is not active.');
        }

        $membership = $user->membershipIn($tenantId)
            ?? throw new \RuntimeException('That user is not a member of that tenant.');

        $payload = $this->payload->for($user, $tenantId);

        $token = $this->jwt->createFromPayload($user, [
            TokenAudience::CLAIM => TokenAudience::App->value,
            'tid' => $tenantId,
            'role' => $membership->role(),
            'roles' => $membership->securityRoles(),
            Impersonation::CLAIM => true,
            Impersonation::ACTOR_CLAIM => $actor,
            // Overrides the bundle's configured TTL for this token only.
            'exp' => time() + Impersonation::TTL_SECONDS,
        ]);

        return [
            'token' => $token,
            'expiresIn' => Impersonation::TTL_SECONDS,
            'tenantId' => $tenantId,
            // The same shape login returns, so the tenant app can adopt an
            // impersonated session through exactly one code path. Spread into
            // named keys rather than `...`, so the declared return shape stays
            // checkable rather than collapsing to array<string, mixed>.
            'user' => $payload['user'],
            'memberships' => $payload['memberships'],
            'role' => $payload['role'],
            'permissions' => $payload['permissions'],
            'viewingAs' => [
                'id' => (string) $user->id(),
                'email' => $user->email(),
                'displayName' => $user->displayName(),
                'role' => $membership->role(),
            ],
        ];
    }
}
