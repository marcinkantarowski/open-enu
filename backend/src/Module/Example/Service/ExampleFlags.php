<?php

declare(strict_types=1);

namespace App\Module\Example\Service;

use OpenEnu\Kernel\Flags\FlagDefinition;
use OpenEnu\Kernel\Flags\FlagProviderInterface;

/**
 * The flags this module reads, declared beside the code they guard.
 *
 * Implementing the interface is the whole registration step - the kernel tags
 * it, and `app:flags:sync` makes it a row an operator can switch. Without a
 * declaration `#[Flag('example.archive')]` would resolve to the attribute's
 * default forever: invisible in the console, and impossible to turn off during
 * an incident, which is the one job a kill switch has.
 */
final readonly class ExampleFlags implements FlagProviderInterface
{
    public function flags(): iterable
    {
        yield new FlagDefinition(
            identifier: 'example.archive',
            name: 'Bulk archive',
            description: 'Allows archiving every active project at once. Off by default - it is the module\'s demonstration of a kill switch.',
            // Off, so that the route 404s until somebody decides otherwise. A
            // new capability that arrives switched on has been decided for you.
            default: false,
            tenantEditable: true,
            category: 'example',
        );
    }
}
