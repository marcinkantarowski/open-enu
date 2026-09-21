<?php

declare(strict_types=1);

namespace App\Module\ApiKey\Controller\Api;

use App\Module\ApiKey\Command\CreateApiKey;
use App\Module\ApiKey\Command\RevokeApiKey;
use App\Module\ApiKey\Repository\ApiKeyRepository;
use OpenEnu\Kernel\Command\CommandBusInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final readonly class ApiKeyController
{
    public function __construct(
        private ApiKeyRepository $keys,
        private CommandBusInterface $commands,
    ) {
    }

    #[Route('/api/api-keys', name: 'api_key_index', methods: ['GET'])]
    #[IsGranted('api_key.view')]
    public function index(): JsonResponse
    {
        return new JsonResponse([
            'items' => array_map(static fn (object $k): array => $k->toArray(), $this->keys->forCurrentTenant()),
        ]);
    }

    /**
     * Requires `api_key.manage`, which is owner-only and which no key is ever
     * granted by policy: a credential that can mint credentials removes the
     * point of scoping them.
     */
    #[Route('/api/api-keys', name: 'api_key_create', methods: ['POST'])]
    #[IsGranted('api_key.manage')]
    public function create(Request $request): JsonResponse
    {
        /** @var array<string, mixed> $body */
        $body = json_decode($request->getContent() ?: '{}', true) ?: [];

        $name = $body['name'] ?? null;
        $permissions = $body['permissions'] ?? null;

        if (!\is_string($name) || trim($name) === '') {
            throw new BadRequestHttpException('"name" is required.');
        }
        if (!\is_array($permissions) || $permissions === []) {
            throw new BadRequestHttpException('"permissions" must be a non-empty list - a key with none grants nothing.');
        }

        return new JsonResponse(
            $this->commands->dispatch(new CreateApiKey(
                name: trim($name),
                permissions: array_values(array_filter($permissions, is_string(...))),
                expiresInDays: \is_int($body['expiresInDays'] ?? null) ? $body['expiresInDays'] : null,
            )),
            Response::HTTP_CREATED,
        );
    }

    #[Route('/api/api-keys/{id}', name: 'api_key_revoke', methods: ['DELETE'])]
    #[IsGranted('api_key.manage')]
    public function revoke(string $id): JsonResponse
    {
        $this->commands->dispatch(new RevokeApiKey($id));

        return new JsonResponse(['status' => 'revoked']);
    }
}
