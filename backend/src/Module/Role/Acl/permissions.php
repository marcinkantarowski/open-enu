<?php

declare(strict_types=1);

/**
 * Permissions this module defines.
 *
 * Permissions are global: the kernel refuses to boot if two modules declare the
 * same string, because a permission with two owners has no meaning.
 *
 * A plain member holds whatever ends in `.view` or `.use` until the tenant
 * changes its roles; everything else needs an admin. Add `role.use` only for
 * work every member of a team does. Each one needs a `permission.<name>` label
 * in i18n/ - it is a line on the roles screen (ADR-0025).
 */
return [
    'role.view',
    'role.manage',
];
