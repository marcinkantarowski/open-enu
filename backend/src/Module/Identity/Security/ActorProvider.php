<?php

declare(strict_types=1);

namespace App\Module\Identity\Security;

use App\Module\Identity\Entity\User;
use OpenEnu\Kernel\Contract\ActorProviderInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;

/**
 * Tells the audit trail who is acting.
 *
 * Decorates the kernel's null provider (.ai/platform/PLAN.md §6.10) - the kernel records
 * audit entries and has no idea what a user is, which is the direction of
 * dependency that keeps it replaceable.
 *
 * `onBehalfOfId` carries the operator behind an impersonated session, so an
 * impersonated change is attributable to both identities. Without it the trail
 * says a customer did what their support agent did (ADR-0008).
 */
#[AsDecorator(decorates: 'OpenEnu\Kernel\Command\NullActorProvider')]
final readonly class ActorProvider implements ActorProviderInterface
{
    public function __construct(private Security $security)
    {
    }

    public function actorId(): ?string
    {
        $user = $this->security->getUser();

        return $user instanceof User ? (string) $user->id() : null;
    }

    public function onBehalfOfId(): ?string
    {
        $token = $this->security->getToken();
        if ($token === null) {
            return null;
        }

        // Set by the manager realm when it mints an impersonation token.
        // hasAttribute first: getAttribute THROWS on a missing key rather than
        // returning null, so the ?? was never reached - and every ordinary
        // request died in the audit path.
        if (!$token->hasAttribute('act')) {
            return null;
        }

        $act = $token->getAttribute('act');

        return \is_array($act) && \is_string($act['sub'] ?? null) ? $act['sub'] : null;
    }
}
