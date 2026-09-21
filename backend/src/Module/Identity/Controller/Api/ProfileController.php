<?php

declare(strict_types=1);

namespace App\Module\Identity\Controller\Api;

use App\Module\Identity\Command\UpdateProfile;
use App\Module\Identity\Entity\User;
use OpenEnu\Kernel\Command\CommandBusInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Your own account.
 *
 * Under `/api/profile`, NOT `/api/auth/profile`: everything below `/api/auth/`
 * is PUBLIC_ACCESS in `security.yaml`, because that prefix is the set of
 * endpoints that establish a session. Putting an authenticated route there would
 * inherit that rule and expose it - the kind of mistake a reader of the route
 * alone would never spot.
 */
final readonly class ProfileController
{
    public function __construct(private CommandBusInterface $commands)
    {
    }

    #[Route('/api/profile', name: 'profile_show', methods: ['GET'])]
    // Any signed-in user, and said so: it is the caller's own record, and
    // requiring a grant to read yourself locks people out of their own
    // settings. Declared rather than omitted, because an action with no
    // attribute is indistinguishable from one somebody forgot (`make test-arch`).
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function show(#[CurrentUser] User $user): JsonResponse
    {
        // No permission check: this is the caller's own record, and requiring a
        // grant to read yourself would lock people out of their own settings.
        return new JsonResponse($user->toArray());
    }

    #[Route('/api/profile', name: 'profile_update', methods: ['PATCH'])]
    #[IsGranted('identity.profile.manage')]
    public function update(#[CurrentUser] User $user, Request $request): JsonResponse
    {
        /** @var array<string, mixed> $body */
        $body = json_decode($request->getContent() ?: '{}', true) ?? [];

        if (!\is_array($body)) {
            throw new BadRequestHttpException('Expected a JSON object.');
        }

        return new JsonResponse($this->commands->dispatch(new UpdateProfile(
            userId: (string) $user->id(),
            displayName: \is_string($body['displayName'] ?? null) ? $body['displayName'] : null,
            locale: \is_string($body['locale'] ?? null) ? $body['locale'] : null,
        )));
    }
}
