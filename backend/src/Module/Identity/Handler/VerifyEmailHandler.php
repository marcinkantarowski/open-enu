<?php

declare(strict_types=1);

namespace App\Module\Identity\Handler;

use App\Module\Identity\Command\VerifyEmail;
use App\Module\Identity\Entity\SecurityToken;
use App\Module\Identity\Service\SecurityTokenIssuer;
use App\Module\Tenant\Contract\TenantProvisionerInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class VerifyEmailHandler
{
    public function __construct(
        private SecurityTokenIssuer $tokens,
        private TenantProvisionerInterface $tenants,
        private EntityManagerInterface $em,
    ) {
    }

    /** @return array<string, mixed> */
    public function __invoke(VerifyEmail $command): array
    {
        $token = $this->tokens->redeem($command->rawToken, SecurityToken::PURPOSE_VERIFY_EMAIL);
        $user = $token?->user();

        if ($user === null) {
            // One message for expired, unknown and already-used. Distinguishing
            // them tells an attacker which links were real.
            throw new BadRequestHttpException('This verification link is invalid or has expired.');
        }

        $user->verify();
        $userId = (string) $user->id();
        $tenantId = $token?->payload()['tenantId'] ?? null;

        // Flushed BEFORE the tenant is activated, and deliberately not after.
        // Activation crosses a scope boundary, and `runUnscoped()` clears the
        // EntityManager on the way out - the verified user and the consumed
        // token would be detached by the time a later flush ran, and the write
        // would silently not happen. Guarded now, but the order is the point.
        // See [[unflushed-work-does-not-survive-rununscoped]].
        $this->em->flush();

        // The tenant created at signup stays `pending` until its first user
        // proves their address. Without that, an open signup form is a
        // tenant-spam endpoint, and the purge job has nothing to purge.
        //
        // Through the contract, not the repository: how activation works is the
        // Tenant module's business (ADR-0002).
        if (\is_string($tenantId)) {
            $this->tenants->activate($tenantId);
        }

        return ['status' => 'verified', 'userId' => $userId];
    }
}
