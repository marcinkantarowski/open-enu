<?php

declare(strict_types=1);

namespace OpenEnu\Kernel\Tests\Cache;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use OpenEnu\Kernel\Cache\TenantCache;
use OpenEnu\Kernel\Tests\Support\BuildsScopeContext;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Adapter\TagAwareAdapter;

/**
 * Two properties, one of which is a data leak and the other an outage.
 *
 * Against a REAL tag-aware adapter, not a mock: the bug this suite was written
 * after was a reserved character in a tag, which a mock happily accepts and a
 * real pool rejects with an exception - on the request trying to read a feature
 * flag, nowhere near where the tag was built.
 */
#[CoversClass(TenantCache::class)]
final class TenantCacheTest extends TestCase
{
    use BuildsScopeContext;

    /**
     * Each call gets its OWN adapter unless one is shared, so a test comparing
     * two tenants must pass the same pool - otherwise it proves nothing.
     */
    private function cacheFor(?string $tenantId, ?TagAwareAdapter $pool = null): TenantCache
    {
        return new TenantCache(
            $pool ?? new TagAwareAdapter(new ArrayAdapter()),
            $this->scopeContext($tenantId),
        );
    }

    public function testTagsContainNoReservedCharacters(): void
    {
        // PSR-6 reserves {}()/\@: in keys, and Symfony applies it to tags too.
        foreach ([
            TenantCache::moduleTag('settings'),
            TenantCache::tenantTag('0192f000-0000-7000-8000-000000000000'),
            TenantCache::sanitiseTag('weird:tag/with\\stuff@here'),
        ] as $tag) {
            self::assertDoesNotMatchRegularExpression('/[{}()\/\\\\@:]/', $tag);
        }
    }

    public function testAValueIsComputedOnceAndThenServedFromCache(): void
    {
        $cache = $this->cacheFor('tenant-a');
        $calls = 0;

        $compute = static function () use (&$calls): string {
            ++$calls;

            return 'computed';
        };

        self::assertSame('computed', $cache->get('demo', 'thing', $compute));
        self::assertSame('computed', $cache->get('demo', 'thing', $compute));
        self::assertSame(1, $calls);
    }

    public function testOneTenantNeverSeesAnothersCachedValue(): void
    {
        // THE reason this class exists. A key computed for one tenant and served
        // to another is a leak that query scoping cannot prevent, because no
        // query runs.
        // ONE pool, two tenants: the point is that the prefix keeps them apart
        // even when they share storage, which is the real deployment.
        $pool = new TagAwareAdapter(new ArrayAdapter());
        $a = $this->cacheFor('tenant-a', $pool);
        $b = $this->cacheFor('tenant-b', $pool);

        self::assertSame('a-value', $a->get('demo', 'shared-key', static fn (): string => 'a-value'));
        self::assertSame('b-value', $b->get('demo', 'shared-key', static fn (): string => 'b-value'));
    }

    public function testInvalidatingATenantDropsItsEntriesImmediately(): void
    {
        // What makes a kill switch a kill switch: the next request, not whenever
        // the entry happens to expire.
        $cache = $this->cacheFor('tenant-a');
        $value = 'first';

        self::assertSame('first', $cache->get('settings', 'flag', static fn (): string => $value));

        $value = 'second';
        self::assertSame('first', $cache->get('settings', 'flag', static fn (): string => $value), 'still cached');

        $cache->invalidateTenant('tenant-a');

        self::assertSame('second', $cache->get('settings', 'flag', static fn (): string => $value));
    }

    public function testInvalidatingAModuleDropsItsEntries(): void
    {
        $cache = $this->cacheFor('tenant-a');
        $value = 'first';

        $cache->get('settings', 'flag', static fn (): string => $value);
        $value = 'second';

        $cache->invalidateModule('settings');

        self::assertSame('second', $cache->get('settings', 'flag', static fn (): string => $value));
    }

    public function testWithNoTenantEntriesAreNamespacedGlobally(): void
    {
        // Reference data and pre-authentication reads still need somewhere to
        // live; they must not collide with a tenant's.
        $pool = new TagAwareAdapter(new ArrayAdapter());
        $global = $this->cacheFor(null, $pool);
        $tenant = $this->cacheFor('tenant-a', $pool);

        self::assertSame('global', $global->get('demo', 'k', static fn (): string => 'global'));
        self::assertSame('scoped', $tenant->get('demo', 'k', static fn (): string => 'scoped'));
    }
}
