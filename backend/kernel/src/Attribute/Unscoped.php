<?php

declare(strict_types=1);

namespace OpenEnu\Kernel\Attribute;

/**
 * Marks a repository method that queries outside the tenant filter.
 *
 * Two jobs, both about making a dangerous thing visible rather than preventing
 * it (some queries genuinely must be unscoped - provisioning, platform metrics,
 * migrations):
 *
 *  1. It satisfies RawSqlRule, so writing raw SQL is a deliberate act.
 *  2. It is greppable. "Show me every query in this codebase that can see across
 *     tenants" is `grep -r '#\[Unscoped'` - one command, complete answer.
 *
 * The reason is required. "Because the ORM was awkward here" is a reason worth
 * having to write down.
 */
#[\Attribute(\Attribute::TARGET_METHOD)]
final readonly class Unscoped
{
    public function __construct(public string $reason)
    {
    }
}
