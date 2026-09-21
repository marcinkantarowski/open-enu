<?php

declare(strict_types=1);

namespace App\Module\Webhook\Service;

use OpenEnu\Kernel\Notification\NotificationDefinition;
use OpenEnu\Kernel\Notification\NotificationProviderInterface;

/**
 * The notification this module raises, declared beside the code that raises it.
 *
 * One type, and it earns its place: an endpoint that has stopped responding is
 * invisible otherwise. The tenant finds out when they notice missing data, which
 * is usually days later and never from us.
 */
final readonly class WebhookNotifications implements NotificationProviderInterface
{
    public const string DELIVERY_FAILED = 'webhook.delivery_failed';

    public function notifications(): iterable
    {
        yield new NotificationDefinition(
            type: self::DELIVERY_FAILED,
            titleKey: 'notification.webhook.delivery_failed.title',
            bodyKey: 'notification.webhook.delivery_failed.body',
            // Email as well as the feed: the point of this one is to reach
            // somebody who is not looking at the app.
            channels: [NotificationDefinition::CHANNEL_FEED, NotificationDefinition::CHANNEL_EMAIL],
        );
    }
}
