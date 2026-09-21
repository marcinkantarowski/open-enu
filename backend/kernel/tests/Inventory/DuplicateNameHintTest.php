<?php

declare(strict_types=1);

namespace OpenEnu\Kernel\Tests\Inventory;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use OpenEnu\Kernel\Inventory\DuplicateNameHint;

/**
 * An advisory check still has to work.
 *
 * It fires on nothing in this repository today, which is the desired state and
 * also indistinguishable from a check that cannot fire at all. These tests are
 * the difference. See [[a-mechanism-with-no-way-in-is-untested-by-construction]].
 */
#[CoversClass(DuplicateNameHint::class)]
final class DuplicateNameHintTest extends TestCase
{
    public function testItNoticesASecondImplementationUnderANearlyIdenticalName(): void
    {
        $pairs = (new DuplicateNameHint())->pairs(['modules' => [
            'Billing' => ['services' => ['InvoiceFormatter']],
            'Reporting' => ['services' => ['InvoiceFormater']],
        ]]);

        self::assertCount(1, $pairs);
        // Both sides named, with where they live: the hint is only useful if
        // the reader can go and compare the two.
        self::assertStringContainsString('InvoiceFormatter (Billing/services)', $pairs[0]);
        self::assertStringContainsString('InvoiceFormater (Reporting/services)', $pairs[0]);
    }

    public function testUnrelatedNamesAreNotReported(): void
    {
        $pairs = (new DuplicateNameHint())->pairs(['modules' => [
            'Billing' => ['services' => ['InvoiceFormatter', 'PaymentGateway']],
        ]]);

        self::assertSame([], $pairs);
    }

    public function testShortNamesAreIgnoredBecauseTheyCollideByCoincidence(): void
    {
        $pairs = (new DuplicateNameHint())->pairs(['modules' => [
            'A' => ['services' => ['Job']],
            'B' => ['services' => ['Jobs']],
        ]]);

        self::assertSame([], $pairs);
    }

    public function testRoutesAndPermissionsAreNotCompared(): void
    {
        $pairs = (new DuplicateNameHint())->pairs(['modules' => [
            'Example' => [
                'permissions' => ['example.view', 'example.viewx'],
                'routes' => ['GET /api/projects (example_project_index)', 'GET /api/projecta (example_project_indey)'],
            ],
        ]]);

        // Permissions and routes in one module are SUPPOSED to look alike.
        // Reporting them would be noise on every single module, which is how an
        // advisory check teaches people to stop reading it.
        self::assertSame([], $pairs);
    }
}
