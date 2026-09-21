<?php

declare(strict_types=1);

namespace OpenEnu\Kernel\Attribute;

/**
 * Exempts a route from the "every endpoint has a functional test" check.
 *
 * The exemption is deliberately awkward: it needs a written reason, and it shows
 * up in the coverage report as an exemption rather than as coverage. Health
 * endpoints and debug routes qualify; "I'll add the test later" does not.
 */
#[\Attribute(\Attribute::TARGET_METHOD)]
final readonly class NoTestRequired
{
    public function __construct(public string $reason)
    {
    }
}
