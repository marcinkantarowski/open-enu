<?php

declare(strict_types=1);

namespace OpenEnu\Kernel\Flags;

/**
 * Gates a controller action behind a feature flag.
 *
 *     #[Flag('billing.new_checkout')]
 *
 * A disabled flag makes the route 404, not 403: 403 tells the caller the feature
 * exists and they cannot have it, which is information a kill switch should not
 * be leaking.
 */
#[\Attribute(\Attribute::TARGET_METHOD | \Attribute::TARGET_CLASS)]
final readonly class Flag
{
    public function __construct(
        public string $identifier,
        public bool $default = false,
    ) {
    }
}
