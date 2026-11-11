<?php

declare(strict_types=1);

namespace App\Module\Identity\Security;

use App\Module\Identity\Entity\User;
use App\Module\Unit\Contract\UnitTreeInterface;
use OpenEnu\Kernel\Doctrine\ScopeContext;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Establishes the tenant scope from the authenticated token.
 *
 * This is the single point at which "who is asking" becomes "what they can see".
 * Everything downstream - the query filter, the cache prefix, storage keys,
 * audit entries - reads from ScopeContext, and none of them has to know about
 * authentication.
 *
 * Priority 6: after the firewall (8) has authenticated, and before controllers
 * or any service can issue a query. Earlier and there is no token; later and the
 * first query of the request runs unscoped, which the fail-closed filter turns
 * into an empty result rather than a leak - but an empty result nobody can
 * explain is its own kind of bad.
 */
final readonly class ScopeListener
{
    public function __construct(
        private Security $security,
        private ScopeContext $scope,
        private UnitTreeInterface $units,
    ) {
    }

    #[AsEventListener(event: KernelEvents::REQUEST, priority: 6)]
    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $user = $this->security->getUser();

        $tenantId = $user instanceof User ? $user->sessionTenantId() : null;

        // No token, or a token with no tenant: the scope stays empty, and the
        // filter returns nothing for scoped entities. Fail closed (ADR-0004).
        if ($tenantId === null) {
            return;
        }

        // The whole tenant first: the tree is tenant-scoped, and reading it
        // needs the tenant in scope.
        $this->scope->enterTenant($tenantId);

        $membership = $user->membershipIn($tenantId);
        // No active membership: no units either, rather than all of them.
        $unitIds = $membership !== null ? $membership->unitIds() : [];

        // Narrowed, with each unit's subtree, from the tree as it is NOW - so
        // a moved unit or a narrowed membership applies to this very request,
        // not when the token expires (ADR-0025). A membership whose units
        // were all removed expands to nothing, and sees nothing.
        if ($unitIds !== null) {
            $this->scope->enterUnits($tenantId, $this->units->expand($unitIds));
        }
    }
}
