<?php

declare(strict_types=1);

/**
 * Permissions this module defines.
 *
 * Permissions are global: the kernel refuses to boot if two modules declare the
 * same string, because a permission with two owners has no meaning.
 *
 * Two is the right number for most modules. `.view` is granted to every role by
 * PermissionResolver's rule that a plain member reads; anything not ending in
 * `.view` needs a role somebody deliberately granted.
 */
return [
    'example.view',
    'example.manage',
];
