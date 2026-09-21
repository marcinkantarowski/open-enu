<?php

declare(strict_types=1);

namespace OpenEnu\Kernel\Event;

use Psr\Log\LoggerInterface;
use OpenEnu\Kernel\Doctrine\ScopeContext;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Pushes `#[ClientBroadcast]` domain events to the browser.
 *
 * The bridge between "something happened in the database" and "the open tab
 * shows it", without any module writing realtime code.
 *
 * Two things make this safe to have at all:
 *
 *  • **Opt-in.** A domain event is internal by default. Broadcasting one means
 *    its `payload()` reaches every user of that tenant with an open connection,
 *    and the mistake is invisible until someone reads their own data in a
 *    colleague's browser.
 *  • **Tenant-scoped topics.** `/tenants/{tid}/events`, and a subscriber's token
 *    is restricted to their own tenant's topic. Publishing to `/events` would
 *    mean every tenant receiving every tenant's updates.
 *
 * A publish failure is logged, never thrown: the change already happened, and
 * the hub being down must not retry a committed write.
 */
#[AsMessageHandler]
final readonly class ClientBroadcaster
{
    public function __construct(
        private HubInterface $hub,
        private ScopeContext $scope,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(DomainEvent $event): void
    {
        $reflection = new \ReflectionClass($event);
        $attributes = $reflection->getAttributes(ClientBroadcast::class);

        if ($attributes === []) {
            return;
        }

        $tenantId = $this->scope->tenantId();

        if ($tenantId === null) {
            // No tenant means no topic anyone is entitled to receive. Dropping
            // is correct - the alternative is inventing a global topic, which
            // is exactly the cross-tenant leak the scoping exists to prevent.
            $this->logger->warning('broadcast.skipped_no_tenant', ['event' => $event->eventName()]);

            return;
        }

        $broadcast = $attributes[0]->newInstance();

        try {
            $this->hub->publish(new Update(
                sprintf('/tenants/%s/events', $tenantId),
                json_encode([
                    'event' => $event->eventName(),
                    'subjectId' => $event->subjectId,
                    'occurredAt' => $event->occurredAt->format(\DATE_ATOM),
                    'payload' => $event->payload(),
                    'requiresPermission' => $broadcast->requiresPermission,
                ], \JSON_THROW_ON_ERROR),
                // Private: only subscribers whose token names this topic receive
                // it. A public update would be readable by anyone who guessed
                // the tenant id.
                private: true,
            ));
        } catch (\Throwable $e) {
            $this->logger->error('broadcast.failed', [
                'event' => $event->eventName(),
                'error' => $e->getMessage(),
            ]);
        }
    }
}
