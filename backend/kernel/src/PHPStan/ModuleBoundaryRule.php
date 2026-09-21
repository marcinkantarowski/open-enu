<?php

declare(strict_types=1);

namespace OpenEnu\Kernel\PHPStan;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * A module may reach into another module only through its `Contract\`.
 *
 * This is ADR-0002 made executable. Without it, "modules are decoupled" survives
 * about three months of ordinary work: importing another module's entity is
 * always the shortest path, and nothing pushes back.
 *
 * Allowed across a module boundary:
 *   App\Module\Other\Contract\*   - the other module's published surface
 *   App\Module\Other\Event\*      - its events, which exist to be subscribed to
 *   OpenEnu\Kernel\*             - the framework
 *   anything outside App\Module\
 *
 * Refused: Entity, Service, Repository, Controller, Dto - another module's
 * internals, which it must stay free to change.
 *
 * @implements Rule<Node\Stmt\Use_>
 */
final class ModuleBoundaryRule implements Rule
{
    private const string MODULE_PREFIX = 'App\\Module\\';

    /** Sub-namespaces another module is allowed to import. */
    private const array PUBLIC_SEGMENTS = ['Contract', 'Event'];

    public function getNodeType(): string
    {
        return Node\Stmt\Use_::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        if ($node->type !== Node\Stmt\Use_::TYPE_NORMAL) {
            return [];
        }

        $namespace = $scope->getNamespace() ?? '';

        $current = $this->moduleOf($namespace);
        if ($current === null) {
            return [];
        }

        // A test builds the scenario it asserts on, and a scenario often spans
        // modules: a Demo test needs a Tenant to scope to. Forcing fixtures
        // through published contracts would make tests depend on production APIs
        // that have no reason to support what a fixture needs - and the coupling
        // does not ship.
        if (str_contains($namespace, '\\Tests\\')) {
            return [];
        }

        $errors = [];
        foreach ($node->uses as $use) {
            $imported = $use->name->toString();
            $target = $this->moduleOf($imported);

            if ($target === null || $target === $current) {
                continue;
            }

            $segment = explode('\\', substr($imported, \strlen(self::MODULE_PREFIX . $target . '\\')))[0] ?? '';
            if (\in_array($segment, self::PUBLIC_SEGMENTS, true)) {
                continue;
            }

            $errors[] = RuleErrorBuilder::message(sprintf(
                'Module %s must not import %s from module %s.',
                $current,
                $imported,
                $target,
            ))
                ->identifier('openEnu.moduleBoundary')
                ->tip(sprintf(
                    "Cross a module boundary through its published surface only:\n"
                    . "  • read  - depend on an interface in App\\Module\\%s\\Contract\\ and inject it\n"
                    . "  • react - subscribe to an event in App\\Module\\%s\\Event\\\n"
                    . "  • write - dispatch a command or a message; never call another module's service directly\n"
                    . 'See ADR-0002 and .ai/platform/PLAN.md §6.10.',
                    $target,
                    $target,
                ))
                ->build();
        }

        return $errors;
    }

    /** Module name for a class or namespace, or null when it is not module code. */
    private function moduleOf(string $name): ?string
    {
        if (!str_starts_with($name, self::MODULE_PREFIX)) {
            return null;
        }

        $rest = substr($name, \strlen(self::MODULE_PREFIX));
        $module = explode('\\', $rest)[0];

        return $module !== '' ? $module : null;
    }
}
