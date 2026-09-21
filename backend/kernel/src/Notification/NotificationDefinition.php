<?php

declare(strict_types=1);

namespace OpenEnu\Kernel\Notification;

/**
 * A kind of notification a module can raise.
 *
 * Declared rather than invented at the call site, for the same reason flags are:
 * an undeclared type has no name anyone can translate, no default channels, and
 * nothing a user could switch off. The registry is also what lets a preferences
 * screen exist at all - it cannot list types nobody wrote down.
 */
final readonly class NotificationDefinition
{
    public const string CHANNEL_FEED = 'feed';
    public const string CHANNEL_EMAIL = 'email';

    /** @param list<string> $channels */
    public function __construct(
        /** Dotted and namespaced by module: `webhook.delivery_failed`. */
        public string $type,
        /** Translation key for the title. A literal here fails `make i18n-check`. */
        public string $titleKey,
        public string $bodyKey,
        /** Where it goes by default. The feed is always included; email is opt-in. */
        public array $channels = [self::CHANNEL_FEED],
    ) {
    }

    public function sendsEmail(): bool
    {
        return \in_array(self::CHANNEL_EMAIL, $this->channels, true);
    }
}
