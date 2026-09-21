<?php

declare(strict_types=1);

namespace OpenEnu\Kernel\Tests\PHPStan;

use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use OpenEnu\Kernel\PHPStan\PlatformServiceRule;

/**
 * Modules use the kernel's platform services rather than the raw collaborators.
 *
 * A raw cache pool has no tenant prefix, so a value computed for one tenant can
 * be served to another - a leak no amount of query scoping prevents, because no
 * query runs.
 *
 * @extends RuleTestCase<PlatformServiceRule>
 */
final class PlatformServiceRuleTest extends RuleTestCase
{
    use RuleTestHelperTrait;

    protected function getRule(): Rule
    {
        return new PlatformServiceRule();
    }

    public function testItRedirectsRawCacheAndFilesystemImportsAndAllowsTheKernelOnes(): void
    {
        $this->assertRuleErrors([$this->fixture('platform-service-bypass.php')], [
            [
                'Use OpenEnu\Kernel\Storage\StorageInterface instead of League\Flysystem\FilesystemOperator.',
                7,
            ],
            [
                'Use OpenEnu\Kernel\Cache\TenantCache instead of Psr\Cache\CacheItemPoolInterface.',
                8,
            ],
        ]);
    }
}
