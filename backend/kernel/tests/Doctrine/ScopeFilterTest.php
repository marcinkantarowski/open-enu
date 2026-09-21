<?php

declare(strict_types=1);

namespace OpenEnu\Kernel\Tests\Doctrine;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use OpenEnu\Kernel\Contract\TenantScopedInterface;
use OpenEnu\Kernel\Doctrine\ScopeContext;
use OpenEnu\Kernel\Doctrine\ScopeFilter;

/**
 * The single most consequential behaviour in the system (ADR-0004).
 *
 * If this filter omits its predicate when the scope is unknown, every
 * unauthenticated route, un-stamped worker message and forgetful CLI command
 * silently returns every tenant's data - and looks completely normal doing it.
 * So the no-scope case is asserted explicitly, not assumed.
 */
#[CoversClass(ScopeFilter::class)]
final class ScopeFilterTest extends TestCase
{
    /**
     * @param class-string $class
     *
     * @return ClassMetadata<object>
     */
    private function metadataFor(string $class): ClassMetadata
    {
        // The intersection keeps both halves visible to static analysis: the
        // generic ClassMetadata the filter consumes, and the MockObject methods
        // used to configure it.
        /** @var ClassMetadata<object>&MockObject $meta */
        $meta = $this->createMock(ClassMetadata::class);
        $meta->method('getReflectionClass')->willReturn(new \ReflectionClass($class));

        return $meta;
    }

    private function filter(): ScopeFilter
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('quote')->willReturnCallback(
            static fn (string $v): string => "'" . $v . "'",
        );

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($connection);

        return new ScopeFilter($em);
    }

    public function testAScopedEntityWithNoTenantInContextMatchesNothing(): void
    {
        $filter = $this->filter();
        $filter->setScope([]);

        self::assertSame(
            '1 = 0',
            $filter->addFilterConstraint($this->metadataFor(ScopedFixture::class), 't'),
            'With no tenant established the filter MUST match nothing. Omitting the '
            . 'predicate instead would return every tenant\'s rows - the failure mode '
            . 'this design exists to make impossible.',
        );
    }

    public function testAScopedEntityIsNarrowedToTheCurrentTenant(): void
    {
        $filter = $this->filter();
        $filter->setScope([ScopeContext::TENANT => '018f-aaaa']);

        self::assertSame(
            "t.tenant_id = '018f-aaaa'",
            $filter->addFilterConstraint($this->metadataFor(ScopedFixture::class), 't'),
        );
    }

    public function testAnUnscopedEntityIsNotConstrained(): void
    {
        // Reference data - currencies, feature flags, the tenant table itself -
        // must stay readable without a tenant, or nothing could bootstrap.
        $filter = $this->filter();
        $filter->setScope([]);

        self::assertSame(
            '',
            $filter->addFilterConstraint($this->metadataFor(UnscopedFixture::class), 't'),
        );
    }
}

final class ScopedFixture implements TenantScopedInterface
{
    public function tenantId(): string
    {
        return '018f-aaaa';
    }
}

final class UnscopedFixture
{
}
