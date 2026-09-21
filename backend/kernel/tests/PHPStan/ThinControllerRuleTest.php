<?php

declare(strict_types=1);

namespace OpenEnu\Kernel\Tests\PHPStan;

use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use OpenEnu\Kernel\PHPStan\ThinControllerRule;

/**
 * ADR-0002: a controller that can write to the database will end up holding
 * business logic, and that logic is then unreachable from a command or a worker.
 *
 * @extends RuleTestCase<ThinControllerRule>
 */
final class ThinControllerRuleTest extends RuleTestCase
{
    use RuleTestHelperTrait;

    protected function getRule(): Rule
    {
        return new ThinControllerRule();
    }

    public function testItRefusesAnEntityManagerInAControllerConstructorOrAction(): void
    {
        $this->assertRuleErrors([$this->fixture('fat-controller.php')], [
            [
                'Controller App\Module\Billing\Controller\Api\InvoiceController must not depend on Doctrine\ORM\EntityManagerInterface.',
                11,
            ],
            [
                'Controller App\Module\Billing\Controller\Api\InvoiceController must not depend on Doctrine\ORM\EntityManagerInterface.',
                15,
            ],
        ]);
    }
}
