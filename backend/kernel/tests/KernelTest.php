<?php

declare(strict_types=1);

namespace OpenEnu\Kernel\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use OpenEnu\Kernel\Kernel;

/**
 * Guards the rename boundary (ADR-0016).
 *
 * `make init` renames the application and must never rename the kernel. If it
 * ever does, every project built from this checkout silently loses the ability
 * to pull framework updates - with no error at the time.
 *
 * These assertions deliberately compare the constants against composer.json
 * rather than against literals: comparing a literal to itself proves nothing,
 * and a rename would rewrite both sides in lockstep anyway.
 */
#[CoversClass(Kernel::class)]
final class KernelTest extends TestCase
{
    /** @return array{name: string, autoload: array{"psr-4": array<string, string>}} */
    private function composerJson(): array
    {
        $path = \dirname(__DIR__) . '/composer.json';
        self::assertFileExists($path);

        $raw = file_get_contents($path);
        self::assertIsString($raw);

        /** @var array{name: string, autoload: array{"psr-4": array<string, string>}} $data */
        $data = json_decode($raw, true, 512, \JSON_THROW_ON_ERROR);

        return $data;
    }

    public function testPackageNameMatchesTheKernelConstant(): void
    {
        self::assertSame(
            $this->composerJson()['name'],
            Kernel::NAME,
            'Kernel::NAME must match the composer package name. If `make init` rewrote one '
            . 'of them, the kernel has been renamed and the upgrade path is broken (ADR-0016).',
        );
    }

    public function testAutoloadPrefixIsIndependentOfTheProjectName(): void
    {
        $prefixes = array_keys($this->composerJson()['autoload']['psr-4']);

        self::assertContains(
            'OpenEnu\\Kernel\\',
            $prefixes,
            'The kernel PSR-4 prefix must stay OpenEnu\\Kernel\\ regardless of the project name.',
        );
    }

    public function testVersionIsSemver(): void
    {
        // The version is the contract marker a project pins against once the
        // kernel is published, so it must always be parseable.
        self::assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', Kernel::VERSION);
    }
}
