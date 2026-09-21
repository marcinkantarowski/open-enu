<?php

declare(strict_types=1);

namespace OpenEnu\Kernel\PHPStan;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Controllers validate, delegate and serialize. Nothing else.
 *
 * The specific thing this catches is a controller taking an EntityManager or a
 * Connection. That single dependency is how business logic ends up in the HTTP
 * layer: once a controller can write, it will, and the logic becomes unreachable
 * from a command, a worker or a test that does not speak HTTP.
 *
 * @implements Rule<Node\Stmt\ClassMethod>
 */
final class ThinControllerRule implements Rule
{
    private const array FORBIDDEN = [
        'Doctrine\ORM\EntityManagerInterface' => 'inject a repository, or dispatch a command',
        'Doctrine\ORM\EntityManager' => 'inject a repository, or dispatch a command',
        'Doctrine\DBAL\Connection' => 'raw SQL belongs in a Repository (see RawSqlRule)',
        'Doctrine\Persistence\ManagerRegistry' => 'inject the specific repository you need',
    ];

    public function getNodeType(): string
    {
        return Node\Stmt\ClassMethod::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        $class = $scope->getClassReflection();
        if ($class === null || !str_ends_with($class->getName(), 'Controller')) {
            return [];
        }

        $errors = [];
        foreach ($node->params as $param) {
            $type = $param->type;
            if (!$type instanceof Node\Name) {
                continue;
            }

            $name = $scope->resolveName($type);
            if (!isset(self::FORBIDDEN[$name])) {
                continue;
            }

            $errors[] = RuleErrorBuilder::message(sprintf(
                'Controller %s must not depend on %s.',
                $class->getName(),
                $name,
            ))
                ->identifier('openEnu.thinController')
                ->tip(sprintf(
                    "%s.\nA controller that can write to the database is a controller that will hold "
                    . "business logic, and that logic is then unreachable from a CLI command, a worker "
                    . "or a unit test.\nSee ADR-0002.",
                    self::FORBIDDEN[$name],
                ))
                ->build();
        }

        return $errors;
    }
}
