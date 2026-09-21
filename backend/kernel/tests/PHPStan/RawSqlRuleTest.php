<?php

declare(strict_types=1);

namespace OpenEnu\Kernel\Tests\PHPStan;

use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use OpenEnu\Kernel\PHPStan\RawSqlRule;

/**
 * ADR-0004: the tenant filter scopes ORM queries and cannot see raw SQL.
 *
 * This is the highest-severity rule in the set. An unscoped raw query reads
 * across every tenant, returns plausible-looking data, and is invisible in
 * review - so raw SQL is confined to Repository/ and must announce itself with
 * #[Unscoped], which makes "show me every cross-tenant query" one grep.
 *
 * @extends RuleTestCase<RawSqlRule>
 */
final class RawSqlRuleTest extends RuleTestCase
{
    use RuleTestHelperTrait;

    protected function getRule(): Rule
    {
        return new RawSqlRule();
    }

    public function testItRefusesRawSqlOutsideARepositoryEvenWithTheAttribute(): void
    {
        // The attribute is not a licence to put raw SQL anywhere: someone
        // hunting for data access looks in Repository/, and that has to be where
        // it is.
        $this->assertRuleErrors([$this->fixture('raw-sql.php')], [
            [
                'Raw SQL (Doctrine\DBAL\Connection::fetchAllAssociative) is only allowed inside a Repository; App\Module\Billing\Service\ReportingService is not one.',
                19,
            ],
            [
                'Raw SQL (Doctrine\DBAL\Connection::fetchOne) is only allowed inside a Repository; App\Module\Billing\Service\ReportingService is not one.',
                25,
            ],
        ]);
    }

    public function testInsideARepositoryItRequiresTheUnscopedAttribute(): void
    {
        // The declared query passes; the undeclared one does not.
        $this->assertRuleErrors([$this->fixture('raw-sql-repository.php')], [
            [
                'Raw SQL (Doctrine\DBAL\Connection::fetchAllAssociative) requires #[Unscoped] on the enclosing method.',
                26,
            ],
        ]);
    }
}
