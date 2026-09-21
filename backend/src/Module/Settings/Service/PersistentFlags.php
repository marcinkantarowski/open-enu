<?php

declare(strict_types=1);

namespace App\Module\Settings\Service;

use App\Module\Settings\Repository\SettingOverrideRepository;
use App\Module\Settings\Repository\SettingRepository;
use OpenEnu\Kernel\Cache\TenantCache;
use OpenEnu\Kernel\Doctrine\ScopeContext;
use OpenEnu\Kernel\Flags\FlagsInterface;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;

/**
 * Flags resolved from the database: tenant override → global default → the
 * caller's fallback.
 *
 * Decorates the kernel's configuration-only implementation, so every `#[Flag]`
 * and `FlagsInterface` call written since Phase 2b now reads live values without
 * a single call site changing (.ai/platform/PLAN.md §6.10).
 *
 * **Cached, and tagged by tenant.** This is read on every flag-guarded request,
 * so an uncached resolution would put two queries in front of each. The
 * `tenant:{id}` tag is what makes flipping a flag take effect on the very next
 * request rather than whenever the entry happens to expire - which is the whole
 * point of a kill switch.
 */
#[AsDecorator(decorates: 'OpenEnu\Kernel\Flags\StaticFlags')]
final readonly class PersistentFlags implements FlagsInterface
{
    /** Short: a stale kill switch is worse than a cache miss. */
    private const int TTL = 60;

    public function __construct(
        private FlagsInterface $inner,
        private SettingRepository $settings,
        private SettingOverrideRepository $overrides,
        private TenantCache $cache,
        private ScopeContext $scope,
    ) {
    }

    public function enabled(string $identifier, bool $default = false): bool
    {
        $value = $this->resolve($identifier);

        return match (true) {
            $value === null => $this->inner->enabled($identifier, $default),
            \is_bool($value) => $value,
            \is_string($value) => \in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true),
            \is_int($value) => $value !== 0,
            default => $default,
        };
    }

    public function string(string $identifier, string $default = ''): string
    {
        $value = $this->resolve($identifier);

        return \is_string($value) ? $value : $this->inner->string($identifier, $default);
    }

    public function int(string $identifier, int $default = 0): int
    {
        $value = $this->resolve($identifier);

        return \is_int($value) ? $value : $this->inner->int($identifier, $default);
    }

    public function json(string $identifier, array $default = []): array
    {
        $value = $this->resolve($identifier);

        return \is_array($value) ? $value : $this->inner->json($identifier, $default);
    }

    /**
     * The resolution itself: override first, then the global default.
     *
     * Returns null for "no opinion", which is distinct from a stored `false` -
     * conflating them is how a deliberately-disabled flag reads as unconfigured
     * and falls back to a default of `true`.
     */
    private function resolve(string $identifier): mixed
    {
        $tenantId = $this->scope->tenantId();

        /** @var array{value: mixed}|null $boxed */
        $boxed = $this->cache->get(
            'settings',
            'flag.' . ($tenantId ?? 'global') . '.' . $identifier,
            function () use ($identifier, $tenantId): ?array {
                if ($tenantId !== null) {
                    $override = $this->overrides->rawValueFor($tenantId, $identifier);

                    if ($override !== null) {
                        return $override;
                    }
                }

                $setting = $this->settings->byIdentifier($identifier);

                return $setting === null ? null : ['value' => $setting->defaultValue()];
            },
            extraTags: ['settings.' . $identifier],
            ttl: self::TTL,
        );

        return $boxed['value'] ?? null;
    }
}
