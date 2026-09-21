<?php

declare(strict_types=1);

namespace App\Module\Manager\Controller\Api;

use App\Module\Identity\Contract\SessionMinterInterface;
use App\Module\Manager\Command\StartImpersonation;
use App\Module\Manager\Entity\PlatformManager;
use OpenEnu\Kernel\Command\CommandBusInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The one legitimate crossing between the two realms (ADR-0008).
 *
 * An operator asks, from the manager realm, for a token in the TENANT realm.
 * That token is a genuine `aud: app` token - it passes the audience check
 * honestly rather than being an exception to it - carrying `imp` and `act` so
 * that every request it makes names both identities and the guard can refuse
 * destructive actions.
 *
 * Deliberately not refreshable: no cookie is set, so the session expires in
 * fifteen minutes rather than renewing itself quietly.
 */
final readonly class ImpersonationController
{
    public function __construct(
        private SessionMinterInterface $sessions,
        private CommandBusInterface $commands,
        private Security $security,
    ) {
    }

    #[Route(
        '/api/manager/tenants/{tenantId}/users/{userId}/impersonate',
        name: 'manager_impersonate',
        methods: ['POST'],
    )]
    #[IsGranted('ROLE_PLATFORM_MANAGER')]
    public function start(string $tenantId, string $userId): JsonResponse
    {
        $operator = $this->security->getUser();

        if (!$operator instanceof PlatformManager) {
            // Unreachable through the manager firewall; asserted rather than
            // assumed, because the consequence of being wrong is a tenant user
            // minting themselves an impersonation token.
            throw new BadRequestHttpException('Only a platform operator may impersonate.');
        }

        // Audited BEFORE the token exists. If minting fails, the attempt is
        // still on record - "who tried?" is most of what an audit trail is asked
        // after an incident.
        $this->commands->dispatch(new StartImpersonation(
            tenantId: $tenantId,
            userId: $userId,
            operatorId: (string) $operator->id(),
        ));

        try {
            $session = $this->sessions->mintImpersonationSession($userId, $tenantId, [
                'sub' => (string) $operator->id(),
                'realm' => 'manager',
            ]);
        } catch (\RuntimeException $e) {
            throw new BadRequestHttpException($e->getMessage(), $e);
        }

        return new JsonResponse([
            ...$session,
            // The frontend shows a persistent banner while this is set. An
            // impersonated session the user cannot see is the thing that turns
            // support access into a trust problem.
            'impersonating' => true,
            'operator' => ['id' => (string) $operator->id(), 'displayName' => $operator->displayName()],
        ]);
    }
}
