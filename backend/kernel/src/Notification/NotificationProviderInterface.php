<?php

declare(strict_types=1);

namespace OpenEnu\Kernel\Notification;

/**
 * Implemented by a module to declare the notifications it raises.
 *
 * Same shape as `FlagProviderInterface`, and for the same reason: the kernel
 * owns the extension point so a module raising a notification depends on the
 * kernel rather than on the Notification module.
 *
 * Tagged by the kernel's AUTOCONFIGURED_TAGS map - implement it and nothing
 * else is needed.
 */
interface NotificationProviderInterface
{
    /** @return iterable<NotificationDefinition> */
    public function notifications(): iterable;
}
