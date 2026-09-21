<?php

declare(strict_types=1);

namespace OpenEnu\Kernel\PHPStan;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Modules use the kernel's platform services, not the raw ones underneath.
 *
 * Each redirection below closes a specific, silent failure:
 *
 *  • **Raw cache pool.** A key computed for one tenant and served to another is
 *    a leak that query scoping cannot prevent, because no query runs. TenantCache
 *    prefixes every key with the tenant and tags it for invalidation.
 *
 *  • **Direct filesystem access.** A module writing files itself has to remember
 *    the tenant prefix at every call site; missing it once puts one tenant's
 *    upload where another can read it.
 *
 *  • **Raw crypto.** Field encryption has to use the tenant's derived key and
 *    the versioned payload format, or the value is unreadable after rotation.
 *
 * The rule reports the import, which is where the decision was made.
 *
 * @implements Rule<Node\Stmt\Use_>
 */
final class PlatformServiceRule implements Rule
{
    /** imported class => [what to use instead, why] */
    private const array REDIRECT = [
        'Psr\Cache\CacheItemPoolInterface' => [
            'OpenEnu\Kernel\Cache\TenantCache',
            'a raw pool has no tenant prefix, so one tenant\'s cached value can be served to another',
        ],
        'Symfony\Contracts\Cache\CacheInterface' => [
            'OpenEnu\Kernel\Cache\TenantCache',
            'a raw pool has no tenant prefix, so one tenant\'s cached value can be served to another',
        ],
        'Symfony\Contracts\Cache\TagAwareCacheInterface' => [
            'OpenEnu\Kernel\Cache\TenantCache',
            'tagging by hand is how invalidation gets missed; TenantCache tags every entry',
        ],
        'League\Flysystem\FilesystemOperator' => [
            'OpenEnu\Kernel\Storage\StorageInterface',
            'StorageInterface scopes keys to the tenant and issues expiring URLs',
        ],
        'League\Flysystem\Filesystem' => [
            'OpenEnu\Kernel\Storage\StorageInterface',
            'StorageInterface scopes keys to the tenant and issues expiring URLs',
        ],
    ];

    public function getNodeType(): string
    {
        return Node\Stmt\Use_::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        $namespace = $scope->getNamespace() ?? '';

        // The kernel implements these services, so it is the one place that
        // legitimately holds the raw collaborators.
        if (str_starts_with($namespace, 'OpenEnu\\Kernel')) {
            return [];
        }

        $errors = [];
        foreach ($node->uses as $use) {
            $imported = $use->name->toString();
            if (!isset(self::REDIRECT[$imported])) {
                continue;
            }

            [$replacement, $why] = self::REDIRECT[$imported];

            $errors[] = RuleErrorBuilder::message(sprintf(
                'Use %s instead of %s.',
                $replacement,
                $imported,
            ))
                ->identifier('openEnu.platformService')
                ->tip($why . ".\nSee .ai/platform/PLAN.md §6.9 for the full list of platform services.")
                ->build();
        }

        return $errors;
    }
}
