<?php

declare(strict_types=1);

namespace OpenEnu\Kernel\Tests\PHPStan;

use PHPStan\Analyser\Error;

/**
 * Shared framing for the architecture-rule tests.
 *
 * Each of these rules encodes an ADR. A rule that silently stops firing is worse
 * than no rule - the guarantee disappears while the documentation still claims
 * it - so every rule is tested against a fixture that violates it AND against
 * code that legitimately does the same-looking thing and must still be allowed.
 *
 * Assertions cover the message and the line, deliberately not the tip.
 * RuleTestCase::analyse() compares tips too, which would turn every improvement
 * to an error message into a test failure and quietly discourage writing good
 * ones - and the tips are the most useful part of these rules.
 */
trait RuleTestHelperTrait
{
    protected function fixture(string $name): string
    {
        return __DIR__ . '/data/' . $name;
    }

    /**
     * @param list<string>              $files
     * @param list<array{string, int}>  $expected message and line, in order
     */
    protected function assertRuleErrors(array $files, array $expected): void
    {
        $errors = $this->gatherAnalyserErrors($files);

        $actual = array_map(
            static fn (Error $e): array => [$e->getMessage(), $e->getLine()],
            $errors,
        );

        usort($actual, static fn (array $a, array $b): int => $a[1] <=> $b[1]);

        self::assertSame(
            $expected,
            $actual,
            "The architecture rule did not report what was expected.\nActual:\n"
            . implode("\n", array_map(
                static fn (array $e): string => sprintf('  line %d: %s', $e[1], $e[0]),
                $actual,
            )),
        );
    }
}
