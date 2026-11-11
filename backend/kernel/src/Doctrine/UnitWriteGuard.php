<?php

declare(strict_types=1);

namespace OpenEnu\Kernel\Doctrine;

use Doctrine\ORM\Event\PrePersistEventArgs;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use OpenEnu\Kernel\Contract\UnitScopedInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Refuses to write a unit-scoped row into a unit the caller cannot see.
 *
 * The filter guards reads only. Without this, a handler that takes a unit id
 * from a request body writes into a unit its caller has no access to - the
 * same leak as reading it, in the other direction, and nothing looks wrong
 * (ADR-0025).
 *
 * Inside runUnscoped() it stands aside: that is the one deliberate crossing.
 */
final readonly class UnitWriteGuard
{
    public function __construct(private ScopeContext $scope)
    {
    }

    public function prePersist(PrePersistEventArgs $args): void
    {
        $this->check($args->getObject());
    }

    public function preUpdate(PreUpdateEventArgs $args): void
    {
        $this->check($args->getObject());
    }

    private function check(object $entity): void
    {
        if (!$entity instanceof UnitScopedInterface || $this->scope->isSuspended()) {
            return;
        }

        // No unit set at all: not a narrowed caller but the system - a console
        // command, a fixture, a setup hook - writing a row whose tenant and unit
        // it states itself. A signed-in request always has a set (ScopeListener),
        // so this never exempts a person. Reads stay fail-closed regardless.
        if ($this->scope->get(ScopeContext::UNITS) === null) {
            return;
        }

        if (!$this->scope->allowsUnit($entity->unitId())) {
            throw new AccessDeniedHttpException(sprintf(
                '%s cannot be written to unit %s: it is outside the units this request may see.',
                $entity::class,
                $entity->unitId() ?? '(the whole tenant)',
            ));
        }
    }
}
