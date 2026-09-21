<?php

declare(strict_types=1);

namespace App\Module\ApiKey\Handler;

use App\Module\ApiKey\Command\CreateApiKey;
use App\Module\ApiKey\Entity\ApiKey;
use Doctrine\ORM\EntityManagerInterface;
use OpenEnu\Kernel\Doctrine\ScopeContext;
use OpenEnu\Kernel\Module\ModuleRegistry;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class CreateApiKeyHandler
{
    public function __construct(
        private EntityManagerInterface $em,
        private ScopeContext $scope,
        private ModuleRegistry $modules,
    ) {
    }

    /** @return array<string, mixed> */
    public function __invoke(CreateApiKey $command): array
    {
        $tenantId = $this->scope->tenantId()
            ?? throw new BadRequestHttpException('A key belongs to a tenant; none is in scope.');

        // Only permissions some module actually declares. A typo would otherwise
        // create a key that silently grants nothing, and the failure would
        // surface later as an unexplained 403.
        $known = $this->modules->permissions();
        $unknown = array_values(array_diff($command->permissions, array_keys($known)));

        if ($unknown !== []) {
            throw new BadRequestHttpException(sprintf(
                'Unknown permission(s): %s. Declared permissions: %s',
                implode(', ', $unknown),
                implode(', ', array_keys($known)),
            ));
        }

        // sk_ prefix, then 32 bytes of entropy. The prefix makes the credential
        // identifiable on sight - in a log, a paste, or a leaked repository.
        $secret = ApiKey::PREFIX . rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');

        $key = new ApiKey(
            tenantId: $tenantId,
            name: $command->name,
            keyHash: hash('sha256', $secret),
            keyPrefix: substr($secret, 0, 12),
            permissions: $command->permissions,
        );

        if ($command->expiresInDays !== null) {
            $key->setExpiresAt(new \DateTimeImmutable('+' . $command->expiresInDays . ' days'));
        }

        $this->em->persist($key);
        $this->em->flush();

        // The only time the secret exists in clear. It is not stored, so it
        // cannot be shown again - which is the correct answer to "can you resend
        // it?" and the reason the response says so.
        return [...$key->toArray(), 'secret' => $secret, 'warning' => 'Store this now - it cannot be shown again.'];
    }
}
