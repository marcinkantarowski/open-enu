<?php

declare(strict_types=1);

namespace OpenEnu\Kernel\Setup;

use Psr\Log\LoggerInterface;

/**
 * Runs every module's tenant setup, in dependency order.
 *
 * Order is not cosmetic: a module that depends on another may reasonably expect
 * that module's defaults to exist by the time it runs. The order comes from the
 * same topological sort the module loader uses, so "depends" means one thing
 * everywhere.
 *
 * A failing module stops the run. Partial provisioning is worse than none - the
 * tenant exists, some of it works, and nobody knows which part.
 */
final readonly class SetupRunner
{
    /** @param iterable<TenantSetupInterface> $providers */
    public function __construct(
        private iterable $providers,
        private LoggerInterface $logger,
    ) {
    }

    public function onTenantCreated(string $tenantId): void
    {
        foreach ($this->providers as $provider) {
            $this->logger->info('tenant setup', ['module' => $provider::class, 'tenant' => $tenantId]);
            $provider->onTenantCreated($tenantId);
        }
    }

    public function seedExamples(string $tenantId): void
    {
        foreach ($this->providers as $provider) {
            $this->logger->info('tenant seed', ['module' => $provider::class, 'tenant' => $tenantId]);
            $provider->seedExamples($tenantId);
        }
    }

    /** @return list<class-string> for `make modules` and the setup report */
    public function providers(): array
    {
        $names = [];
        foreach ($this->providers as $provider) {
            $names[] = $provider::class;
        }

        return $names;
    }
}
