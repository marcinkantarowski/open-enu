<?php

declare(strict_types=1);

namespace App\Module\ApiKey\Security;

use App\Module\ApiKey\Entity\ApiKey;
use App\Module\ApiKey\Repository\ApiKeyRepository;
use Doctrine\ORM\EntityManagerInterface;
use OpenEnu\Kernel\Attribute\InfrastructureWrite;
use OpenEnu\Kernel\Doctrine\ScopeContext;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;

/**
 * Authenticates `Authorization: Bearer sk_…`.
 *
 * Sits in front of the JWT authenticator and claims only requests whose bearer
 * carries the key prefix, so the two never fight over a token.
 *
 * It also establishes the tenant scope, because a key IS the tenant claim -
 * there is no session to read it from.
 */
final class ApiKeyAuthenticator extends AbstractAuthenticator
{
    public function __construct(
        private readonly ApiKeyRepository $keys,
        private readonly ScopeContext $scope,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function supports(Request $request): bool
    {
        return str_starts_with($this->bearer($request) ?? '', ApiKey::PREFIX);
    }

    #[InfrastructureWrite(reason: 'stamping last-used on a key; auditing every authenticated request would bury the trail')]
    public function authenticate(Request $request): Passport
    {
        $presented = $this->bearer($request) ?? '';

        // Hash before lookup: the stored value is a hash, and comparing hashes
        // also makes the lookup constant-time against the index rather than
        // against the secret.
        $key = $this->keys->authenticate(hash('sha256', $presented));

        if ($key === null || !$key->isUsable()) {
            // One message for unknown, revoked and expired. Distinguishing them
            // tells an attacker which keys exist and which merely lapsed.
            throw new CustomUserMessageAuthenticationException('Invalid API key.');
        }

        $key->recordUse();
        $this->em->flush();

        // A key carries its own tenant; there is no session to take it from.
        $this->scope->enter([ScopeContext::TENANT => $key->tenantId()]);

        $user = new ApiKeyUser((string) $key->id(), $key->tenantId(), $key->permissions());

        return new SelfValidatingPassport(new UserBadge(
            $user->getUserIdentifier(),
            static fn (): ApiKeyUser => $user,
        ));
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        return null;
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        return new JsonResponse([
            'error' => ['code' => 'unauthenticated', 'message' => 'Invalid API key.'],
        ], Response::HTTP_UNAUTHORIZED);
    }

    private function bearer(Request $request): ?string
    {
        $header = $request->headers->get('Authorization');

        return \is_string($header) && str_starts_with($header, 'Bearer ')
            ? substr($header, 7)
            : null;
    }
}
