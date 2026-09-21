<?php

declare(strict_types=1);

namespace OpenEnu\Kernel\Tests\PHPStan;

use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use OpenEnu\Kernel\PHPStan\PersistenceBoundaryRule;

/**
 * ADR-0017: the command bus is the only write path, so audit coverage,
 * transactions and optimistic locking apply to every write by construction.
 *
 * @extends RuleTestCase<PersistenceBoundaryRule>
 */
final class PersistenceBoundaryRuleTest extends RuleTestCase
{
    use RuleTestHelperTrait;

    protected function getRule(): Rule
    {
        return new PersistenceBoundaryRule();
    }

    public function testAWriteOutsideAHandlerIsRefused(): void
    {
        $this->assertRuleErrors([$this->fixture('persistence-outside-handler.php')], [
            [
                'Persistence (persist()) is only allowed in a command handler; App\Module\Billing\Service\InvoiceService is not one.',
                17,
            ],
            [
                'Persistence (flush()) is only allowed in a command handler; App\Module\Billing\Service\InvoiceService is not one.',
                18,
            ],
        ]);
    }

    public function testAWriteInsideAHandlerIsAllowed(): void
    {
        // The rule must not make the architecture impossible to use: a handler
        // is exactly where a write belongs.
        $this->assertRuleErrors([$this->fixture('persistence-in-handler.php')], []);
    }
}
