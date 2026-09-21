<?php

declare(strict_types=1);

namespace OpenEnu\Kernel\Command;

use OpenEnu\Kernel\Contract\ActorProviderInterface;

/**
 * The kernel's answer before authentication exists: nobody.
 *
 * Decorated away by the Identity module in Phase 3. Having a real default here
 * means the command bus works - and audits - from the first module, instead of
 * being switched on later.
 */
final readonly class NullActorProvider implements ActorProviderInterface
{
    public function actorId(): ?string
    {
        return null;
    }

    public function onBehalfOfId(): ?string
    {
        return null;
    }
}
