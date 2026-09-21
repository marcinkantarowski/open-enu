<?php

declare(strict_types=1);

namespace OpenEnu\Kernel\Notification;

/**
 * Tells someone that something happened.
 *
 * Two audiences, because they are genuinely different questions: `toUser()` is
 * "this concerns you personally", `toTenant()` is "this concerns the workspace"
 * and fans out to whoever should see it. A module raising a notification should
 * not have to decide which people that is.
 *
 * The context is translation arguments, not prose: the message itself is a
 * translation key on the declaration, so the same notification reads correctly
 * in each recipient's own language.
 */
interface NotifierInterface
{
    /** @param array<string, scalar|null> $context */
    public function toUser(string $userId, string $type, array $context = []): void;

    /** @param array<string, scalar|null> $context */
    public function toTenant(string $tenantId, string $type, array $context = []): void;
}
