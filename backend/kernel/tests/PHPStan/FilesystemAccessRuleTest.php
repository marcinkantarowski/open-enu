<?php

declare(strict_types=1);

namespace OpenEnu\Kernel\Tests\PHPStan;

use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use OpenEnu\Kernel\PHPStan\FilesystemAccessRule;

/**
 * The shorter path around storage: writing a file directly, usually with a
 * user-supplied name, which is both a traversal and an unscoped write.
 *
 * @extends RuleTestCase<FilesystemAccessRule>
 */
final class FilesystemAccessRuleTest extends RuleTestCase
{
    use RuleTestHelperTrait;

    protected function getRule(): Rule
    {
        return new FilesystemAccessRule();
    }

    public function testDirectFilesystemWritesAreRefusedInModules(): void
    {
        $this->assertRuleErrors([$this->fixture('platform-service-bypass.php')], [
            [
                'file_put_contents() writes to the filesystem directly; use OpenEnu\Kernel\Storage\StorageInterface.',
                24,
            ],
        ]);
    }

    public function testTheKernelItselfIsUnconstrained(): void
    {
        // The kernel implements storage; constraining it would be circular.
        $this->assertRuleErrors([$this->fixture('kernel-imports-app.php')], []);
    }
}
