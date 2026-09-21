<?php

declare(strict_types=1);

namespace OpenEnu\Kernel\PHPStan;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Module code does not touch the filesystem directly.
 *
 * The import rule catches injecting a Flysystem operator; this catches the
 * shorter path - `file_put_contents($dir . $name, $bytes)` with a user-supplied
 * name, which is both a traversal and an un-scoped write.
 *
 * Reading a file that ships with the code (a template, a fixture) is fine, so
 * only write and delete functions are refused.
 *
 * @implements Rule<Node\Expr\FuncCall>
 */
final class FilesystemAccessRule implements Rule
{
    private const array FORBIDDEN = [
        'file_put_contents', 'unlink', 'rename', 'copy', 'mkdir', 'rmdir', 'touch',
    ];

    public function getNodeType(): string
    {
        return Node\Expr\FuncCall::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        if (!$node->name instanceof Node\Name) {
            return [];
        }

        $function = $node->name->toLowerString();
        if (!\in_array($function, self::FORBIDDEN, true)) {
            return [];
        }

        $class = $scope->getClassReflection()?->getName() ?? '';

        // Only application modules are constrained. The kernel implements
        // storage; tests and fixtures legitimately write scratch files.
        if (!str_starts_with($class, 'App\\Module\\')) {
            return [];
        }
        if (str_contains($class, '\\Tests\\') || str_contains($class, '\\Fixtures\\')) {
            return [];
        }

        return [
            RuleErrorBuilder::message(sprintf(
                '%s() writes to the filesystem directly; use OpenEnu\Kernel\Storage\StorageInterface.',
                $function,
            ))
                ->identifier('openEnu.filesystemAccess')
                ->tip(
                    "StorageInterface scopes every key to the current tenant, so an upload cannot\n"
                    . "land where another tenant can read it, and it generates the file's name so a\n"
                    . "user-supplied one can never become a path.\n"
                    . '  $key = $this->storage->write(\'invoices\', $upload->getClientOriginalName(), $bytes, $mime);',
                )
                ->build(),
        ];
    }
}
