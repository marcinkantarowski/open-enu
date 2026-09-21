<?php

declare(strict_types=1);

namespace OpenEnu\Kernel\Flags;

/**
 * A flag a module declares, so it can be seen and switched.
 *
 * Without a declaration a `#[Flag]` resolves to the attribute's default and
 * nothing else: it does not appear in the operator console, it has no per-tenant
 * override to attach to, and it can never be turned off during an incident -
 * which is the one job a kill switch has.
 *
 * The module that owns the behaviour owns the declaration, beside the code the
 * flag guards, rather than in a central list that grows a merge conflict per
 * feature branch.
 */
final readonly class FlagDefinition
{
    public const string TYPE_BOOL = 'bool';
    public const string TYPE_STRING = 'string';
    public const string TYPE_INT = 'int';
    public const string TYPE_JSON = 'json';

    public function __construct(
        /** Dotted and namespaced by module: `example.archive`, never `archive`. */
        public string $identifier,
        public string $name,
        public string $description,
        public mixed $default = false,
        public string $type = self::TYPE_BOOL,
        /**
         * Whether a tenant may change this for itself.
         *
         * `false` for anything that is a platform kill switch: a tenant
         * re-enabling a feature an operator turned off during an incident
         * defeats the point of turning it off.
         */
        public bool $tenantEditable = false,
        public ?string $category = null,
    ) {
    }
}
