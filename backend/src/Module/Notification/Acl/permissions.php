<?php

declare(strict_types=1);

/**
 * Permissions this module defines.
 *
 * Just the one. A feed is personal: the endpoints scope to the caller, so
 * "may I read notifications" is the only question, and "whose" is never a
 * parameter.
 */
return [
    'notification.view',
];
