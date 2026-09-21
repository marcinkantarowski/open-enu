<?php

declare(strict_types=1);

namespace OpenEnu\Kernel\Http\Controller;

use Lcobucci\JWT\Encoding\ChainedFormatter;
use Lcobucci\JWT\Encoding\JoseEncoder;
use Lcobucci\JWT\Signer\Hmac\Sha256;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Token\Builder;
use OpenEnu\Kernel\Doctrine\ScopeContext;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Issues the subscriber token the browser needs to open an SSE connection.
 *
 * `EventSource` cannot set headers, so the credential has to be a cookie - which
 * is why this endpoint exists rather than the frontend reusing its access token.
 *
 * The token's `subscribe` claim is restricted to the caller's own tenant topic.
 * A `["*"]` claim, which is the obvious thing to write, would let any
 * authenticated user receive every tenant's updates - a cross-tenant leak that
 * no query filter can see, because no query runs.
 */
final readonly class RealtimeTokenController
{
    public function __construct(
        private ScopeContext $scope,
        private string $mercureSecret,
        private bool $secureCookies,
    ) {
    }

    #[Route('/api/realtime/token', name: 'kernel_realtime_token', methods: ['POST'])]
    public function issue(): JsonResponse
    {
        $tenantId = $this->scope->tenantId()
            ?? throw new AccessDeniedHttpException('Realtime updates require a tenant scope.');

        // An empty signing secret produces tokens the hub accepts from anyone,
        // which is indistinguishable from working until someone tries it. Fail
        // at the first request instead.
        if ($this->mercureSecret === '') {
            throw new \RuntimeException('MERCURE_JWT_SECRET is empty; realtime tokens would be unsigned.');
        }

        $topic = sprintf('/tenants/%s/events', $tenantId);

        $token = (new Builder(new JoseEncoder(), ChainedFormatter::default()))
            ->withClaim('mercure', [
                // This tenant's topics and nothing else.
                'subscribe' => [$topic, sprintf('/tenants/%s/progress/{id}', $tenantId)],
            ])
            ->expiresAt(new \DateTimeImmutable('+1 hour'))
            ->getToken(new Sha256(), InMemory::plainText($this->mercureSecret));

        $response = new JsonResponse(['topic' => $topic]);

        $response->headers->setCookie(
            Cookie::create('mercureAuthorization')
                ->withValue($token->toString())
                ->withExpires(new \DateTimeImmutable('+1 hour'))
                // Scoped to the hub's path so it is not sent to the API on
                // every request.
                ->withPath('/.well-known/mercure')
                // Host-only, deliberately: no Domain attribute. The hub is served
                // from api.${DOMAIN}/.well-known/mercure, the host that sets this.
                // `Domain=.example.com` would also be sent to every host under
                // it - staging at *.stg.example.com included - handing a
                // production subscriber token to a different stack.
                ->withDomain(null)
                ->withSecure($this->secureCookies)
                ->withHttpOnly(true)
                ->withSameSite(Cookie::SAMESITE_STRICT),
        );

        return $response;
    }
}
