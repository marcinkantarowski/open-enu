<?php

declare(strict_types=1);

namespace OpenEnu\Kernel\Cache;

use OpenEnu\Kernel\Doctrine\ScopeContext;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\Cache\TagAwareCacheInterface;

/**
 * The only cache modules are allowed to use.
 *
 * Two problems it exists to make impossible:
 *
 *  1. **Cross-tenant cache poisoning.** A key like `invoice_totals` computed for
 *     tenant A and served to tenant B is a data leak that no amount of query
 *     scoping prevents, because the query never runs. Every key here is prefixed
 *     with the current tenant, so the collision cannot be written.
 *
 *  2. **Invalidation nobody can perform.** Deleting a tenant, or changing
 *     something that half a dozen modules derive from, needs one action rather
 *     than a hunt for every key that might be stale. Every entry is tagged
 *     `tenant:{id}` and `module:{name}`, so both are a single call.
 *
 * Raw cache pools are banned in modules by PlatformServiceRule.
 *
 * Tags use a dot, not a colon. PSR-6 reserves `{}()/\@:` in keys, and Symfony
 * applies the same rule to tags - a colon produces a runtime exception from the
 * cache layer, on the request that was trying to read a feature flag.
 */
final readonly class TenantCache
{
    public function __construct(
        private TagAwareCacheInterface $cache,
        private ScopeContext $scope,
    ) {
    }

    /**
     * @template T
     *
     * @param callable(ItemInterface):T $compute
     * @param list<string>              $extraTags
     *
     * @return T
     */
    public function get(string $module, string $key, callable $compute, array $extraTags = [], ?int $ttl = null): mixed
    {
        $tags = [self::moduleTag($module), ...array_map(self::sanitiseTag(...), $extraTags)];

        $tenant = $this->scope->tenantId();
        if ($tenant !== null) {
            $tags[] = self::tenantTag($tenant);
        }

        return $this->cache->get(
            $this->key($module, $key),
            static function (ItemInterface $item) use ($compute, $tags, $ttl): mixed {
                $item->tag($tags);
                if ($ttl !== null) {
                    $item->expiresAfter($ttl);
                }

                return $compute($item);
            },
        );
    }

    public function delete(string $module, string $key): void
    {
        $this->cache->delete($this->key($module, $key));
    }

    /** Drop everything a module cached, for every tenant. */
    public function invalidateModule(string $module): void
    {
        $this->cache->invalidateTags([self::moduleTag($module)]);
    }

    /** Drop everything cached for one tenant. Called when a tenant is deleted. */
    public function invalidateTenant(string $tenantId): void
    {
        $this->cache->invalidateTags([self::tenantTag($tenantId)]);
    }

    public static function moduleTag(string $module): string
    {
        return 'module.' . self::sanitiseTag($module);
    }

    public static function tenantTag(string $tenantId): string
    {
        return 'tenant.' . self::sanitiseTag($tenantId);
    }

    /**
     * Tags are subject to the same reserved characters as keys.
     *
     * Sanitising centrally rather than trusting callers: a tag built from a
     * module name or an identifier will eventually contain something reserved,
     * and the failure is an exception thrown from the cache layer during an
     * ordinary read - nowhere near where the tag was written.
     */
    public static function sanitiseTag(string $tag): string
    {
        return preg_replace('#[{}()/\\\\@:]#', '.', $tag) ?? $tag;
    }

    /**
     * Keys are namespaced by tenant even though they are also tagged by it:
     * tags drive invalidation, the prefix prevents the collision in the first
     * place. Relying on tags alone would mean a key written before the scope was
     * established could still be served to the wrong tenant.
     */
    private function key(string $module, string $key): string
    {
        $tenant = $this->scope->tenantId() ?? 'global';

        // Reserved characters in PSR-6 keys; hashing the tail keeps arbitrary
        // identifiers usable as cache keys without sanitising at every call site.
        return sprintf('%s.%s.%s', $tenant, $module, preg_replace('/[^A-Za-z0-9_.]/', '_', $key) ?? $key);
    }
}
