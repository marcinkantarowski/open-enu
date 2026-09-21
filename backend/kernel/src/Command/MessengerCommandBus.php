<?php

declare(strict_types=1);

namespace OpenEnu\Kernel\Command;

use OpenEnu\Kernel\Contract\ActorProviderInterface;
use OpenEnu\Kernel\Contract\AuditEntry;
use OpenEnu\Kernel\Contract\AuditLoggerInterface;
use OpenEnu\Kernel\Doctrine\ScopeContext;
use OpenEnu\Kernel\Http\RequestId;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\HandleTrait;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * The one way a write happens (ADR-0017).
 *
 * Audit coverage is structural rather than remembered: every dispatch produces
 * an entry, including a failed one. A handler cannot forget to audit, because it
 * is not the handler doing it.
 *
 * The transaction comes from Messenger's `doctrine_transaction` middleware, so a
 * handler that throws leaves no partial write - and the audit entry recording
 * that failure is written *after* the rollback, by this class, which is outside
 * it.
 */
final class MessengerCommandBus implements CommandBusInterface
{
    use HandleTrait;

    public function __construct(
        MessageBusInterface $messageBus,
        private readonly AuditLoggerInterface $audit,
        private readonly ActorProviderInterface $actor,
        private readonly ScopeContext $scope,
        private readonly SnapshotCollector $snapshots,
        private readonly RequestStack $requests,
    ) {
        $this->messageBus = $messageBus;
    }

    public function dispatch(CommandInterface $command): mixed
    {
        $this->snapshots->reset();
        $startedAt = microtime(true);

        try {
            $result = $this->handle($command);
        } catch (\Throwable $e) {
            // Messenger wraps whatever the handler threw. Unwrap it, so the
            // caller sees its own exception and not a framework one.
            $actual = $e instanceof HandlerFailedException
                ? ($e->getPrevious() ?? $e)
                : $e;

            $this->audit->record($this->entry($command, $startedAt)->failed(
                $actual::class . ': ' . $actual->getMessage(),
            ));

            throw $actual;
        }

        $this->audit->record($this->entry($command, $startedAt, $this->snapshots->take()));

        return $result;
    }

    /** @param array{0: array<string, mixed>|null, 1: array<string, mixed>|null} $snapshots */
    private function entry(CommandInterface $command, float $startedAt, array $snapshots = [null, null]): AuditEntry
    {
        $request = $this->requests->getCurrentRequest();

        $context = ['duration_ms' => (int) round((microtime(true) - $startedAt) * 1000)];

        // An impersonated change must name both identities, or the trail says a
        // customer did what their support agent did (ADR-0008).
        $onBehalfOf = $this->actor->onBehalfOfId();
        if ($onBehalfOf !== null) {
            $context['on_behalf_of'] = $onBehalfOf;
        }

        return new AuditEntry(
            action: $command->auditAction(),
            subjectId: $command->auditSubjectId(),
            actorId: $this->actor->actorId(),
            // Ambient scope first, and the command's own answer when there is
            // none: an operator acting from the manager realm has no tenant in
            // scope, and an entry written without one disappears from the
            // per-tenant trail. See TenantTargetedInterface.
            tenantId: $this->scope->tenantId()
                ?? ($command instanceof TenantTargetedInterface ? $command->auditTenantId() : null),
            requestId: $request !== null ? RequestId::fromRequest($request) : null,
            before: $snapshots[0],
            after: $snapshots[1],
            context: $context,
            at: new \DateTimeImmutable(),
        );
    }
}
