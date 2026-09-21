<?php

declare(strict_types=1);

/**
 * Permissions this module defines.
 *
 * Permissions are global: the kernel refuses to boot if two modules declare the
 * same string, because a permission with two owners has no meaning.
 */
return [
    'webhook.view',
    'webhook.manage',
];
