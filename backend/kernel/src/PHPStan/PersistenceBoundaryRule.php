<?php

declare(strict_types=1);

namespace OpenEnu\Kernel\PHPStan;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\ObjectType;
use OpenEnu\Kernel\Attribute\InfrastructureWrite;

/**
 * Writes happen in a command handler. Nowhere else.
 *
 * `flush()` is the moment a change becomes real, and it is exactly one method
 * call away from any service that happens to hold an EntityManager. Allowing it
 * anywhere means audit coverage, transaction boundaries and optimistic locking
 * all become things each author has to remember - which is another way of saying
 * they become optional (ADR-0017).
 *
 * Confining it to Handler/ makes the command bus the only write path, and the
 * command bus audits unconditionally.
 *
 * @implements Rule<Node\Expr\MethodCall>
 */
final class PersistenceBoundaryRule implements Rule
{
    private const array WRITE_METHODS = ['flush', 'persist', 'remove'];

    public function getNodeType(): string
    {
        return Node\Expr\MethodCall::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        if (!$node->name instanceof Node\Identifier) {
            return [];
        }

        $method = $node->name->toString();
        if (!\in_array($method, self::WRITE_METHODS, true)) {
            return [];
        }

        $type = $scope->getType($node->var);
        if (!(new ObjectType('Doctrine\Persistence\ObjectManager'))->isSuperTypeOf($type)->yes()) {
            return [];
        }

        $class = $scope->getClassReflection()?->getName() ?? '';

        // The kernel implements the machinery (the bus, the setup runner, the
        // GDPR walker), so it is the one place that legitimately writes outside
        // a handler.
        if (str_starts_with($class, 'OpenEnu\\Kernel\\')) {
            return [];
        }

        // Bootstrap paths, all outside the request lifecycle: there is no actor
        // to attribute a write to, no client-supplied version to lock against,
        // and no caller waiting on a result. Routing tenant provisioning through
        // the command bus would mean a command per module's default rows - real
        // ceremony for an audit entry with a null actor.
        //
        //   Fixtures/   loading data is their entire purpose
        //   Migrations/ Doctrine's own generated output
        //   Setup/      TenantSetupInterface, run once when a tenant is created
        //   Tests/      a test builds the state it needs; forcing fixtures
        //               through the command bus would test the bus, not the
        //               behaviour under test
        foreach (['\\Fixtures\\', '\\Migrations\\', '\\Setup\\', '\\Tests\\'] as $bootstrap) {
            if (str_contains($class, $bootstrap)) {
                return [];
            }
        }

        if (str_contains($class, '\\Handler\\')) {
            return [];
        }

        // An explicitly-declared infrastructure write: session rotation, a
        // last-used stamp, a progress counter. Requires a written reason and
        // stays greppable, exactly like #[Unscoped].
        if ($this->isDeclaredInfrastructure($scope)) {
            return [];
        }

        return [
            RuleErrorBuilder::message(sprintf(
                'Persistence (%s()) is only allowed in a command handler; %s is not one.',
                $method,
                $class !== '' ? $class : '(anonymous)',
            ))
                ->identifier('openEnu.persistenceOutsideHandler')
                ->tip(
                    "Every write goes through the command bus, which audits it, wraps it in a\n"
                    . "transaction and enforces optimistic locking. A write outside that path gets\n"
                    . "none of those, and nothing reports its absence.\n\n"
                    . "Move the change into a Handler/ class and dispatch a command:\n"
                    . "  \$this->commandBus->dispatch(new UpdateInvoice(...));\n"
                    . 'See ADR-0017.',
                )
                ->build(),
        ];
    }

    private function isDeclaredInfrastructure(Scope $scope): bool
    {
        foreach ($scope->getFunction()?->getAttributes() ?? [] as $attribute) {
            if ($attribute->getName() === InfrastructureWrite::class) {
                return true;
            }
        }

        foreach ($scope->getClassReflection()?->getAttributes() ?? [] as $attribute) {
            if ($attribute->getName() === InfrastructureWrite::class) {
                return true;
            }
        }

        return false;
    }
}
