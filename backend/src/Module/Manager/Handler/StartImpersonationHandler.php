<?php

declare(strict_types=1);

namespace App\Module\Manager\Handler;

use App\Module\Manager\Command\StartImpersonation;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Nothing to persist: the command bus writes the audit entry, which IS the
 * record. The handler exists so the command has somewhere to go, and to make the
 * event unmissable in the security log as well as the audit table.
 */
#[AsMessageHandler]
final readonly class StartImpersonationHandler
{
    public function __construct(private LoggerInterface $logger)
    {
    }

    public function __invoke(StartImpersonation $command): void
    {
        $this->logger->notice('impersonation.started', [
            'operator' => $command->operatorId,
            'viewing_as' => $command->userId,
            'tenant' => $command->tenantId,
        ]);
    }
}
