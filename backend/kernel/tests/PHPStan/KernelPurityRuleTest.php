<?php

declare(strict_types=1);

namespace OpenEnu\Kernel\Tests\PHPStan;

use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use OpenEnu\Kernel\PHPStan\KernelPurityRule;

/**
 * ADR-0016: the kernel is a package the application depends on, never the
 * reverse. One `use App\...` inside it ends the upgrade path silently.
 *
 * @extends RuleTestCase<KernelPurityRule>
 */
final class KernelPurityRuleTest extends RuleTestCase
{
    use RuleTestHelperTrait;

    protected function getRule(): Rule
    {
        return new KernelPurityRule();
    }

    public function testItRefusesApplicationImportsInsideTheKernel(): void
    {
        $this->assertRuleErrors([$this->fixture('kernel-imports-app.php')], [
            [
                'The kernel must not import application code (App\Module\Billing\Entity\Invoice).',
                7,
            ],
        ]);
    }

    public function testItIgnoresApplicationCodeImportingApplicationCode(): void
    {
        // The same import from a non-kernel namespace is ordinary and allowed.
        $this->assertRuleErrors([$this->fixture('cross-module-import.php')], []);
    }
}
