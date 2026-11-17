<?php

declare(strict_types=1);

/**
 * Permissions this module defines.
 *
 * Inviting and managing are separate on purpose: a front desk may bring people
 * in at the role it is allowed to hand out, without being able to change
 * anyone already there.
 */
return [
    'member.view',
    'member.invite',
    'member.manage',
];
