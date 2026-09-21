<?php

declare(strict_types=1);

namespace App\Module\Notification\Service;

use OpenEnu\Kernel\Notification\NotificationDefinition;
use OpenEnu\Kernel\Notification\NotificationProviderInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Every notification type any module declares.
 *
 * Held in memory rather than in a table: unlike a feature flag, a type has
 * nothing an operator would edit - it is a name, two translation keys and a
 * default channel list, all of which belong to the code that raises it.
 */
final readonly class NotificationCatalogue
{
    /** @param iterable<NotificationProviderInterface> $providers */
    public function __construct(
        #[AutowireIterator('open_enu.notification_provider')] private iterable $providers,
    ) {
    }

    /** @return array<string, NotificationDefinition> */
    public function all(): array
    {
        $types = [];

        foreach ($this->providers as $provider) {
            foreach ($provider->notifications() as $definition) {
                if (isset($types[$definition->type])) {
                    // Global, like permissions: a type with two owners has two
                    // meanings and whichever module loaded last would win.
                    throw new \LogicException(sprintf('Notification type "%s" is declared twice.', $definition->type));
                }

                $types[$definition->type] = $definition;
            }
        }

        return $types;
    }

    public function find(string $type): ?NotificationDefinition
    {
        return $this->all()[$type] ?? null;
    }
}
