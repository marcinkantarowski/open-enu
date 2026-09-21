<?php

declare(strict_types=1);

namespace OpenEnu\Kernel\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * The scope every query is narrowed to - today the tenant, later possibly more.
 *
 * Request-scoped state, and therefore dangerous in a long-running worker: a
 * value left over from the previous message would scope the next one to the
 * wrong tenant. Hence ResetInterface, which Symfony calls between messages and
 * between requests.
 *
 * Written for N dimensions from the start (ADR-0014): adding `organization_id`
 * later is another entry in the map and a claim in the token, not a rewrite of
 * every query in the system.
 */
final class ScopeContext implements ResetInterface
{
    public const string TENANT = 'tenant_id';

    /** @var array<string, string> dimension => value */
    private array $scope = [];

    private bool $suspended = false;

    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /** @return array<string, string> */
    public function all(): array
    {
        return $this->scope;
    }

    public function get(string $dimension): ?string
    {
        return $this->scope[$dimension] ?? null;
    }

    public function tenantId(): ?string
    {
        return $this->get(self::TENANT);
    }

    /**
     * Establish the scope for this request, message or command.
     *
     * @param array<string, string> $scope
     */
    public function enter(array $scope): void
    {
        $this->scope = $scope;
        $this->apply();
    }

    public function reset(): void
    {
        $this->scope = [];
        $this->suspended = false;
        $this->apply();
    }

    public function isSuspended(): bool
    {
        return $this->suspended;
    }

    /**
     * Run something outside the scope filter.
     *
     * Legitimate uses exist - provisioning a tenant, platform metrics, a
     * migration - but every one of them can read across tenants, so the reason
     * is required and the call is deliberately conspicuous.
     *
     * The EntityManager is cleared on the way out, always. Without that, an
     * entity loaded here stays in the identity map, and a later scoped `find()`
     * returns it straight from memory without ever issuing the SQL the filter
     * would have constrained - a tenant leak with no query to blame for it.
     *
     * @template T
     *
     * @param callable():T $callback
     *
     * @return T
     */
    public function runUnscoped(string $reason, callable $callback): mixed
    {
        if ($reason === '') {
            throw new \InvalidArgumentException('runUnscoped() requires a reason; it is recorded and audited.');
        }

        // Nothing unflushed may cross this boundary in either direction, because
        // the clear() below discards it without a word. See assertNothingUnsaved().
        $this->assertNothingUnsaved(
            'Flush before calling it, or move the write inside the callback.',
        );

        $previous = $this->suspended;
        $this->suspended = true;
        $this->apply();

        try {
            $result = $callback();

            $this->assertNothingUnsaved(
                'Flush inside the callback, where the write still belongs to a managed entity.',
            );

            return $result;
        } finally {
            $this->suspended = $previous;
            $this->apply();
            // See the docblock: this is the identity-map bypass, closed.
            $this->em->clear();
        }
    }

    /**
     * Refuse to cross a scope boundary with unsaved work.
     *
     * `runUnscoped()` clears the EntityManager, which detaches every managed
     * entity. A change made before the call and flushed after it is therefore
     * flushed on a DETACHED object: no exception, no SQL, no write. The symptom
     * is a feature that reports success and changes nothing, days later, in a
     * different module from the one that caused it - this repository shipped
     * exactly that twice (email verification and tenant suspension) before the
     * check existed. See [[unflushed-work-does-not-survive-rununscoped]].
     *
     * Failing here costs one loud exception naming the entity; not failing here
     * costs an afternoon of reading the wrong file.
     */
    private function assertNothingUnsaved(string $remedy): void
    {
        if (!$this->em->isOpen()) {
            // A closed manager has already lost the work - a rolled-back
            // transaction, typically. clear() is the recovery, not the damage.
            return;
        }

        $uow = $this->em->getUnitOfWork();
        $uow->computeChangeSets();

        $pending = [
            ...$uow->getScheduledEntityInsertions(),
            ...$uow->getScheduledEntityUpdates(),
            ...$uow->getScheduledEntityDeletions(),
        ];

        if ($pending === []) {
            return;
        }

        // Through the metadata so a Doctrine proxy is named as the entity the
        // author would recognise, not as `Proxies\__CG__\...`.
        $classes = array_values(array_unique(array_map(
            fn (object $entity): string => $this->em->getClassMetadata($entity::class)->getName(),
            $pending,
        )));

        throw new \LogicException(sprintf(
            'runUnscoped() would discard unsaved changes to %s%s. %s',
            implode(', ', \array_slice($classes, 0, 3)),
            \count($classes) > 3 ? ' and others' : '',
            $remedy,
        ));
    }

    /** Push the current state into Doctrine's filter registry. */
    private function apply(): void
    {
        $filters = $this->em->getFilters();

        if ($this->suspended) {
            if ($filters->isEnabled(ScopeFilter::NAME)) {
                $filters->disable(ScopeFilter::NAME);
            }

            return;
        }

        $filter = $filters->isEnabled(ScopeFilter::NAME)
            ? $filters->getFilter(ScopeFilter::NAME)
            : $filters->enable(ScopeFilter::NAME);

        \assert($filter instanceof ScopeFilter);
        $filter->setScope($this->scope);
    }
}
