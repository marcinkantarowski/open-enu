<?php

declare(strict_types=1);

namespace App\Module\Identity\Security;

use App\Module\Identity\Entity\User;
use OpenEnu\Kernel\Contract\ActorProviderInterface;
use OpenEnu\Kernel\Security\Impersonation;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\HttpFoundation\RequestStack;

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
 *
 * Read from the request, where `ImpersonationListener` puts the decoded `act`
 * claim - the one place that sees the token's payload. It used to be looked
 * for on the security token instead, where nothing ever set it: every
 * impersonated change was recorded as the customer's own, and no test wrote
 * anything inside a support session to notice.
 */
#[AsDecorator(decorates: 'OpenEnu\Kernel\Command\NullActorProvider')]
final readonly class ActorProvider implements ActorProviderInterface
{
    public function __construct(
        private Security $security,
        private RequestStack $requests,
    ) {
    }

    public function actorId(): ?string
    {
        $user = $this->security->getUser();

        return $user instanceof User ? (string) $user->id() : null;
    }

    public function onBehalfOfId(): ?string
    {
        // The main request is the one the listener marked when the token was decoded.
        $request = $this->requests->getMainRequest();

        return $request === null ? null : Impersonation::fromRequest($request)['sub'] ?? null;
    }
}
