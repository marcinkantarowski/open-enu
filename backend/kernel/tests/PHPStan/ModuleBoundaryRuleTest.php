<?php

declare(strict_types=1);

namespace OpenEnu\Kernel\Tests\PHPStan;

use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use OpenEnu\Kernel\PHPStan\ModuleBoundaryRule;

/**
 * ADR-0002: a module reaches another module only through Contract\ or Event\.
 *
 * @extends RuleTestCase<ModuleBoundaryRule>
 */
final class ModuleBoundaryRuleTest extends RuleTestCase
{
    use RuleTestHelperTrait;

    protected function getRule(): Rule
    {
        return new ModuleBoundaryRule();
    }

    public function testATestMayBuildAnotherModulesFixtures(): void
    {
        // Deliberate exemption, not a gap: a test spanning modules is normal,
        // and the coupling does not ship. Asserted so the exemption stays
        // scoped to Tests/ rather than quietly widening.
        $this->assertRuleErrors([$this->fixture('cross-module-import-in-test.php')], []);
    }

    public function testItRefusesAnotherModulesInternalsAndAllowsItsPublishedSurface(): void
    {
        // The fixture imports six things. Only the two that reach into another
        // module's internals may be reported; Contract, Event, same-module and
        // kernel imports must all pass, or the rule would make the architecture
        // it is protecting impossible to use.
        $this->assertRuleErrors([$this->fixture('cross-module-import.php')], [
            [
                'Module Billing must not import App\Module\Sales\Entity\Order from module Sales.',
                8,
            ],
            [
                'Module Billing must not import App\Module\Sales\Service\OrderService from module Sales.',
                10,
            ],
        ]);
    }
}
