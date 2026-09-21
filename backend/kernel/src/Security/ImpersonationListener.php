<?php

declare(strict_types=1);

namespace OpenEnu\Kernel\Security;

use Lexik\Bundle\JWTAuthenticationBundle\Event\JWTDecodedEvent;
use Psr\Log\LoggerInterface;
use OpenEnu\Kernel\Attribute\DeniedUnderImpersonation;
use OpenEnu\Kernel\Http\RequestId;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Event\ControllerArgumentsEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Records that a session is impersonated, and refuses what it must not do.
 *
 * Two responsibilities, deliberately together because they share one fact:
 *
 *  1. Lift `imp`/`act` off the decoded token onto the request, so the audit
 *     trail can name BOTH identities. Without that, the record says a customer
 *     did what their support agent did (ADR-0008).
 *  2. Refuse any action tagged #[DeniedUnderImpersonation].
 *
 * Every impersonated request is logged, not merely the guarded ones. "What did
 * support look at?" is a question customers ask, and the answer has to exist.
 */
final readonly class ImpersonationListener
{
    public function __construct(
        private RequestStack $requests,
        private LoggerInterface $logger,
    ) {
    }

    #[AsEventListener(event: 'lexik_jwt_authentication.on_jwt_decoded')]
    public function onTokenDecoded(JWTDecodedEvent $event): void
    {
        $payload = $event->getPayload();

        if (!Impersonation::isImpersonated($payload)) {
            return;
        }

        $actor = Impersonation::actor($payload);

        if ($actor === null) {
            // `imp` without `act` is malformed: an impersonated session that
            // cannot name who is behind it defeats the entire point, so it is
            // refused rather than downgraded to an ordinary session.
            $this->logger->error('impersonation.missing_actor', ['subject' => $payload['sub'] ?? null]);
            $event->markAsInvalid();

            return;
        }

        $request = $this->requests->getCurrentRequest();
        if ($request === null) {
            return;
        }

        $request->attributes->set(Impersonation::ATTRIBUTE, $actor);

        $this->logger->info('impersonation.request', [
            'operator' => $actor['sub'],
            'viewing_as' => $payload['sub'] ?? null,
            'tenant' => $payload['tid'] ?? null,
            'path' => $request->getPathInfo(),
            'method' => $request->getMethod(),
            'request_id' => RequestId::fromRequest($request),
        ]);
    }

    #[AsEventListener(event: 'kernel.controller_arguments')]
    public function onControllerArguments(ControllerArgumentsEvent $event): void
    {
        $actor = Impersonation::fromRequest($event->getRequest());
        if ($actor === null) {
            return;
        }

        /** @var list<DeniedUnderImpersonation> $denials */
        $denials = $event->getAttributes(DeniedUnderImpersonation::class);

        if ($denials === []) {
            return;
        }

        $this->logger->warning('impersonation.denied', [
            'operator' => $actor['sub'],
            'path' => $event->getRequest()->getPathInfo(),
        ]);

        // 403 rather than 404 here, unlike a feature flag: the operator knows
        // the action exists - they can see the button - so hiding it would only
        // be confusing. What they must not get is the action.
        throw new AccessDeniedHttpException($denials[0]->because);
    }
}
