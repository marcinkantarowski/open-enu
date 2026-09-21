<?php

declare(strict_types=1);

namespace OpenEnu\Kernel\Tests\Setup;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use OpenEnu\Kernel\Setup\SetupRunner;
use OpenEnu\Kernel\Setup\TenantSetupInterface;
use OpenEnu\Kernel\Tests\Support\CallOrder;

#[CoversClass(SetupRunner::class)]
final class SetupRunnerTest extends TestCase
{
    private function provider(string $name, CallOrder $order, bool $explode = false): TenantSetupInterface
    {
        return new class($name, $order, $explode) implements TenantSetupInterface {
            public function __construct(
                private readonly string $name,
                private readonly CallOrder $order,
                private readonly bool $explode,
            ) {
            }

            public function onTenantCreated(string $tenantId): void
            {
                if ($this->explode) {
                    throw new \RuntimeException($this->name . ' failed');
                }
                $this->order->record($this->name);
            }

            public function seedExamples(string $tenantId): void
            {
                $this->order->record($this->name . ':seed');
            }
        };
    }

    public function testProvidersRunInTheOrderGiven(): void
    {
        // The iterator arrives in module dependency order, so a module may rely
        // on what it depends on having already run.
        $order = new CallOrder();
        (new SetupRunner([
            $this->provider('tenant', $order),
            $this->provider('identity', $order),
            $this->provider('billing', $order),
        ], new NullLogger()))->onTenantCreated('t-1');

        self::assertSame(['tenant', 'identity', 'billing'], $order->calls());
    }

    public function testAFailingModuleStopsTheRun(): void
    {
        // Partial provisioning is worse than none: the tenant exists, some of it
        // works, and nobody knows which part.
        $order = new CallOrder();
        $runner = new SetupRunner([
            $this->provider('tenant', $order),
            $this->provider('identity', $order, explode: true),
            $this->provider('billing', $order),
        ], new NullLogger());

        $this->expectException(\RuntimeException::class);

        try {
            $runner->onTenantCreated('t-1');
        } finally {
            self::assertSame(['tenant'], $order->calls(), 'Nothing after the failure should have run.');
        }
    }

    public function testSeedingIsSeparateFromCreation(): void
    {
        // Production tenants want defaults and not demo data; merging the two is
        // how sample records reach a customer.
        $order = new CallOrder();
        (new SetupRunner([$this->provider('demo', $order)], new NullLogger()))->seedExamples('t-1');

        self::assertSame(['demo:seed'], $order->calls());
    }
}
