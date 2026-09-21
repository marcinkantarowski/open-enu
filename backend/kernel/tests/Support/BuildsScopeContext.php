<?php

declare(strict_types=1);

namespace OpenEnu\Kernel\Tests\Support;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\Configuration;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query\FilterCollection;
use OpenEnu\Kernel\Doctrine\ScopeContext;
use OpenEnu\Kernel\Doctrine\ScopeFilter;

/**
 * A working `ScopeContext` for unit tests.
 *
 * `ScopeContext::enter()` pushes the scope into Doctrine's filter registry, so a
 * bare mock EntityManager is not enough - the registry has to be able to
 * instantiate the filter. Rather than weaken the production code to tolerate a
 * half-built EntityManager (which would let a genuine misconfiguration pass
 * silently), the test builds one that is real enough.
 *
 * A trait rather than a factory class, so it can use the TestCase's own mock
 * builder instead of driving PHPUnit's generator directly - that API is internal
 * and changes between versions.
 */
trait BuildsScopeContext
{
    protected function scopeContext(?string $tenantId = null): ScopeContext
    {
        $configuration = new Configuration();
        $configuration->addFilter(ScopeFilter::NAME, ScopeFilter::class);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getConfiguration')->willReturn($configuration);
        $em->method('getConnection')->willReturn($this->createMock(Connection::class));
        $em->method('getFilters')->willReturn(new FilterCollection($em));

        $scope = new ScopeContext($em);

        if ($tenantId !== null) {
            $scope->enter([ScopeContext::TENANT => $tenantId]);
        }

        return $scope;
    }
}
