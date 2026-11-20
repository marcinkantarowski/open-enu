<?php

declare(strict_types=1);

namespace App\Module\Manager\Security;

use App\Module\Manager\Entity\PlatformManager;
use OpenEnu\Kernel\Contract\ActorProviderInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;

/**
 * Tells the audit trail which operator is acting in the manager realm.
 *
 * Identity's provider knows tenant users only, so every command dispatched from
 * the operator console - suspending a tenant, starting an impersonation,
 * overriding a flag - was recorded with no actor at all. The trail could say
 * that an operator looked at a customer's account, but not which one, which is
 * the one thing it is asked after an incident (ADR-0008).
 *
 * Wraps Identity's provider rather than replacing it: anything that is not an
 * operator, and the impersonation link in `onBehalfOfId`, is still answered
 * there. The negative priority is what puts this decorator OUTSIDE Identity's -
 * Identity's does not delegate, so whichever is outermost decides.
 */
#[AsDecorator(decorates: 'OpenEnu\Kernel\Command\NullActorProvider', priority: -10)]
final readonly class OperatorActorProvider implements ActorProviderInterface
{
    public function __construct(
        private ActorProviderInterface $inner,
        private Security $security,
    ) {
    }

    public function actorId(): ?string
    {
        $user = $this->security->getUser();

        return $user instanceof PlatformManager ? (string) $user->id() : $this->inner->actorId();
    }

    public function onBehalfOfId(): ?string
    {
        return $this->inner->onBehalfOfId();
    }
}
