<?php

declare(strict_types=1);

namespace OpenEnu\Kernel\Notification;

use Psr\Log\LoggerInterface;

/**
 * Notifications to the log, before the Notification module exists.
 *
 * Deliberately usable rather than a no-op, like `LoggingProgressReporter`: code
 * written against the contract works immediately and its output is visible in
 * `make logs`. The Notification module decorates this to persist a feed and send
 * mail, and no caller changes.
 */
final readonly class LoggingNotifier implements NotifierInterface
{
    public function __construct(private LoggerInterface $logger)
    {
    }

    public function toUser(string $userId, string $type, array $context = []): void
    {
        $this->logger->info('notification', ['to' => 'user:' . $userId, 'type' => $type, 'context' => $context]);
    }

    public function toTenant(string $tenantId, string $type, array $context = []): void
    {
        $this->logger->info('notification', ['to' => 'tenant:' . $tenantId, 'type' => $type, 'context' => $context]);
    }
}
