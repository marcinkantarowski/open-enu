<?php

declare(strict_types=1);

namespace OpenEnu\Kernel\PHPStan;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\ObjectType;

/**
 * Raw SQL lives in Repository/ and is declared with #[Unscoped].
 *
 * This is a tenancy rule, not a style rule. The Doctrine filter that scopes
 * every query to the current tenant operates on the ORM; it cannot see native
 * SQL or DBAL. So a `$connection->executeQuery('SELECT * FROM invoices')`
 * anywhere in the codebase silently reads across every tenant - the highest
 * severity bug this system can have, and completely invisible in review.
 *
 * Two constraints make it survivable:
 *   1. It may only appear in a Repository, where someone looking for data
 *      access will find it.
 *   2. It must carry #[Unscoped('why')], which is greppable - auditing every
 *      unscoped query in the codebase becomes one search.
 *
 * @implements Rule<Node\Expr\MethodCall>
 */
final class RawSqlRule implements Rule
{
    private const array RAW_METHODS = [
        'executeQuery', 'executeStatement', 'fetchAllAssociative', 'fetchAssociative',
        'fetchOne', 'fetchFirstColumn', 'fetchNumeric', 'fetchAllNumeric', 'prepare',
    ];

    private const array RAW_TYPES = [
        'Doctrine\DBAL\Connection',
        'Doctrine\ORM\EntityManagerInterface',
    ];

    private const string ATTRIBUTE = 'OpenEnu\Kernel\Attribute\Unscoped';

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
        $isNativeQuery = $method === 'createNativeQuery';

        if (!$isNativeQuery && !\in_array($method, self::RAW_METHODS, true)) {
            return [];
        }

        $calleeType = $scope->getType($node->var);
        $matched = null;
        foreach (self::RAW_TYPES as $type) {
            if ((new ObjectType($type))->isSuperTypeOf($calleeType)->yes()) {
                $matched = $type;
                break;
            }
        }
        if ($matched === null) {
            return [];
        }
        // createNativeQuery only exists on the EntityManager.
        if ($isNativeQuery && $matched !== 'Doctrine\ORM\EntityManagerInterface') {
            return [];
        }

        $class = $scope->getClassReflection();
        $className = $class?->getName() ?? '(unknown)';

        $inRepository = str_contains($className, '\\Repository\\') || str_ends_with($className, 'Repository');
        $isKernel = str_starts_with($className, 'OpenEnu\\Kernel\\');

        // The kernel implements the scoping machinery itself, so it is the one
        // place in application code that legitimately talks to the driver.
        if ($isKernel) {
            return [];
        }

        // Tests read the driver on purpose: asserting that an #[Encrypted]
        // column really holds ciphertext REQUIRES bypassing the ORM, since the
        // ORM is what would decrypt it. Refusing that would make the encryption
        // guarantee unverifiable.
        if (str_contains($className, '\\Tests\\')) {
            return [];
        }

        if (!$inRepository) {
            return [
                RuleErrorBuilder::message(sprintf(
                    'Raw SQL (%s::%s) is only allowed inside a Repository; %s is not one.',
                    $matched,
                    $method,
                    $className,
                ))
                    ->identifier('openEnu.rawSqlOutsideRepository')
                    ->tip(
                        "The tenant filter scopes ORM queries. It cannot see raw SQL, so an unscoped\n"
                        . "raw query reads across every tenant - silently.\n"
                        . 'Move it into a Repository and mark the method #[Unscoped(reason: "…")].',
                    )
                    ->build(),
            ];
        }

        if (!$this->hasUnscopedAttribute($scope)) {
            return [
                RuleErrorBuilder::message(sprintf(
                    'Raw SQL (%s::%s) requires #[Unscoped] on the enclosing method.',
                    $matched,
                    $method,
                ))
                    ->identifier('openEnu.rawSqlWithoutAttribute')
                    ->tip(
                        "Add #[Unscoped(reason: 'why this cannot be an ORM query')] to the method, and\n"
                        . "add the tenant predicate to the SQL by hand.\n"
                        . 'The attribute makes every unscoped query in the codebase findable with one grep.',
                    )
                    ->build(),
            ];
        }

        return [];
    }

    private function hasUnscopedAttribute(Scope $scope): bool
    {
        $function = $scope->getFunction();
        if ($function === null) {
            return false;
        }

        foreach ($function->getAttributes() as $attribute) {
            if ($attribute->getName() === self::ATTRIBUTE) {
                return true;
            }
        }

        return false;
    }
}
