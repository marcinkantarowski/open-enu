<?php

declare(strict_types=1);

namespace OpenEnu\Kernel\Doctrine;

use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Query\Filter\SQLFilter;
use OpenEnu\Kernel\Contract\TenantScopedInterface;

/**
 * Narrows every query on a scoped entity to the current tenant.
 *
 * **It fails closed.** With no tenant in context the constraint is `1 = 0`, so
 * an unauthenticated route, an un-stamped worker message or a CLI command that
 * forgot `--tenant` returns nothing. The alternative - omitting the predicate
 * when the scope is unknown - means those same mistakes return *every tenant's
 * data*, and they look identical in a code review (ADR-0004).
 *
 * Written for N dimensions. Today the map has one entry.
 *
 * Three things this filter cannot see, closed elsewhere:
 *   • native SQL and DBAL - RawSqlRule confines it to Repository/ with #[Unscoped]
 *   • getReference() - issues no query at all; banned in modules
 *   • the identity map - ScopeContext::runUnscoped() clears the EntityManager
 */
final class ScopeFilter extends SQLFilter
{
    public const string NAME = 'open_enu_scope';

    /**
     * Entity interface => column it is scoped by.
     *
     * @var array<class-string, string>
     */
    private const array DIMENSIONS = [
        TenantScopedInterface::class => ScopeContext::TENANT,
    ];

    /** @var array<string, string>|null */
    private ?array $scope = null;

    /** @param array<string, string> $scope */
    public function setScope(array $scope): void
    {
        $this->scope = $scope;

        foreach (self::DIMENSIONS as $column) {
            if (isset($scope[$column])) {
                $this->setParameter($column, $scope[$column]);
            }
        }
    }

    public function addFilterConstraint(ClassMetadata $targetEntity, string $targetTableAlias): string
    {
        $predicates = [];

        foreach (self::DIMENSIONS as $interface => $column) {
            if (!$targetEntity->getReflectionClass()->implementsInterface($interface)) {
                continue;
            }

            $value = $this->scope[$column] ?? null;

            if ($value === null) {
                // Fail closed. Deliberately not "no constraint": returning every
                // tenant's rows because the scope was not established is the
                // worst possible default, and it is the silent one.
                return '1 = 0';
            }

            $predicates[] = sprintf(
                '%s.%s = %s',
                $targetTableAlias,
                $column,
                $this->getConnection()->quote($value),
            );
        }

        return implode(' AND ', $predicates);
    }
}
