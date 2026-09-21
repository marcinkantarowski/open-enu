<?php

declare(strict_types=1);

namespace App\Module\ApiKey\Handler;

use App\Module\ApiKey\Command\RevokeApiKey;
use App\Module\ApiKey\Repository\ApiKeyRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class RevokeApiKeyHandler
{
    public function __construct(
        private ApiKeyRepository $keys,
        private EntityManagerInterface $em,
    ) {
    }

    public function __invoke(RevokeApiKey $command): void
    {
        // The scope filter means a key from another tenant is simply not found,
        // so this cannot revoke someone else's.
        $key = $this->keys->get($command->keyId) ?? throw new NotFoundHttpException('No such key.');

        $key->revoke();
        $this->em->flush();
    }
}
