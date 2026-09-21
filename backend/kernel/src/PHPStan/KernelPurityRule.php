<?php

declare(strict_types=1);

namespace OpenEnu\Kernel\PHPStan;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * The kernel must not know the application exists.
 *
 * `open-enu/kernel` is a published package that a project can pin and update
 * (ADR-0016). One `use App\...` inside it silently ends that: the package stops
 * being installable anywhere but this application, and the upgrade path every
 * project built from this repo relies on is gone - with no error until someone
 * tries to use it.
 *
 * @implements Rule<Node\Stmt\Use_>
 */
final class KernelPurityRule implements Rule
{
    public function getNodeType(): string
    {
        return Node\Stmt\Use_::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        if (!str_starts_with($scope->getNamespace() ?? '', 'OpenEnu\\Kernel')) {
            return [];
        }

        $errors = [];
        foreach ($node->uses as $use) {
            $imported = $use->name->toString();
            if (!str_starts_with($imported, 'App\\')) {
                continue;
            }

            $errors[] = RuleErrorBuilder::message(sprintf(
                'The kernel must not import application code (%s).',
                $imported,
            ))
                ->identifier('openEnu.kernelPurity')
                ->tip(
                    "open-enu/kernel is a package the application depends on, never the other way round.\n"
                    . "If the kernel needs something the application has, invert it:\n"
                    . "  • define an interface in OpenEnu\\Kernel\\Contract\\ and let the application implement it\n"
                    . "  • collect implementations with a tagged iterator\n"
                    . 'See ADR-0016.',
                )
                ->build();
        }

        return $errors;
    }
}
