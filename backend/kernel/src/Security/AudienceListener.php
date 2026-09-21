<?php

declare(strict_types=1);

namespace OpenEnu\Kernel\Security;

use Lexik\Bundle\JWTAuthenticationBundle\Event\JWTDecodedEvent;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security\FirewallMap;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Rejects a token presented to the wrong realm.
 *
 * Two Symfony firewalls with two user providers LOOK isolated and are not: they
 * share a signing key, so the only thing distinguishing a tenant token from an
 * operator token is which provider happens to resolve the identifier. An email
 * address present in both tables crosses the boundary silently.
 *
 * This makes the realm an explicit, signed claim. Crossing it now requires
 * forging a signature rather than guessing an address.
 *
 * Impersonation is the one legitimate crossing, and it does not happen here: the
 * manager realm MINTS an app-audience token, which then passes this check
 * honestly (ADR-0008).
 */
final readonly class AudienceListener
{
    /** @param array<string, string> $firewallAudiences firewall name => expected audience */
    public function __construct(
        private FirewallMap $firewalls,
        private RequestStack $requests,
        private array $firewallAudiences,
        private LoggerInterface $logger,
    ) {
    }

    #[AsEventListener(event: 'lexik_jwt_authentication.on_jwt_decoded')]
    public function __invoke(JWTDecodedEvent $event): void
    {
        $request = $this->requests->getCurrentRequest();
        if ($request === null) {
            return;
        }

        $config = $this->firewalls->getFirewallConfig($request);
        $firewall = $config?->getName();
        if ($firewall === null) {
            return;
        }

        $expected = $this->firewallAudiences[$firewall] ?? null;
        if ($expected === null) {
            // A firewall with no declared audience is a configuration gap, not a
            // free pass: refuse rather than authenticate something unclassified.
            $this->logger->error('jwt.audience.unmapped', ['firewall' => $firewall]);
            $event->markAsInvalid();

            return;
        }

        $payload = $event->getPayload();
        $actual = $payload[TokenAudience::CLAIM] ?? null;

        // `aud` may be a string or a list, per RFC 7519.
        $audiences = \is_array($actual) ? $actual : [$actual];

        if (!\in_array($expected, $audiences, true)) {
            $this->logger->warning('jwt.audience.mismatch', [
                'firewall' => $firewall,
                'expected' => $expected,
                'presented' => $actual,
                'subject' => $payload['sub'] ?? null,
            ]);
            $event->markAsInvalid();
        }
    }
}
