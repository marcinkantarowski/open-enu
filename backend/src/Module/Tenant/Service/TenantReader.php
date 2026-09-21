<?php

declare(strict_types=1);

namespace App\Module\Tenant\Service;

use App\Module\Tenant\Contract\TenantReaderInterface;
use App\Module\Tenant\Repository\TenantRepository;
use OpenEnu\Kernel\Cache\TenantCache;

/**
 * The implementation behind the contract other modules import.
 *
 * Cached: this is consulted on every authenticated request (to check the tenant
 * is still active), so an uncached read would put a query in front of every
 * single API call. Invalidated by the `tenant:{id}` tag whenever the tenant
 * changes.
 */
final readonly class TenantReader implements TenantReaderInterface
{
    public function __construct(
        private TenantRepository $tenants,
        private TenantCache $cache,
    ) {
    }

    public function exists(string $tenantId): bool
    {
        return $this->describe($tenantId) !== null;
    }

    public function isActive(string $tenantId): bool
    {
        return ($this->describe($tenantId)['status'] ?? null) === 'active';
    }

    public function describe(string $tenantId): ?array
    {
        /** @var array{id: string, slug: string, name: string, status: string, defaultLocale: string}|null $described */
        $described = $this->cache->get(
            'tenant',
            'describe.' . $tenantId,
            function () use ($tenantId): ?array {
                $tenant = $this->tenants->get($tenantId);

                return $tenant === null ? null : [
                    'id' => (string) $tenant->id(),
                    'slug' => $tenant->slug(),
                    'name' => $tenant->name(),
                    'status' => $tenant->status(),
                    'defaultLocale' => $tenant->defaultLocale(),
                ];
            },
            extraTags: [TenantCache::tenantTag($tenantId)],
            ttl: 300,
        );

        return $described;
    }

    public function defaultLocale(string $tenantId): ?string
    {
        return $this->describe($tenantId)['defaultLocale'] ?? null;
    }

    public function page(int $offset, int $limit): array
    {
        // Uncached: the operator console is low-traffic and wants the truth,
        // and a paginated list has no stable key worth caching under.
        return array_map(
            static fn (object $tenant): array => $tenant->toArray(),
            $this->tenants->page($offset, $limit),
        );
    }

    public function total(): int
    {
        return $this->tenants->total();
    }
}
