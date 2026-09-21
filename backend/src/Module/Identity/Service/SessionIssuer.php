<?php

declare(strict_types=1);

namespace App\Module\Identity\Service;

use App\Module\Identity\Entity\Membership;
use App\Module\Identity\Entity\RefreshToken;
use App\Module\Identity\Entity\User;
use App\Module\Identity\Repository\RefreshTokenRepository;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Psr\Log\LoggerInterface;
use OpenEnu\Kernel\Attribute\InfrastructureWrite;
use OpenEnu\Kernel\Security\TokenAudience;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Exception\AuthenticationException;

/**
 * Mints and rotates sessions.
 *
 * The shape is ADR-0006: a short-lived access token the browser holds in memory,
 * and a long-lived refresh token it never sees, because it lives in an httpOnly
 * cookie. An XSS in any dependency can then act as the user while the page is
 * open, but cannot steal a credential that outlives the tab.
 */
final readonly class SessionIssuer
{
    public const string COOKIE = 'open_enu_refresh';
    private const string REFRESH_TTL = 'P30D';

    public function __construct(
        private JWTTokenManagerInterface $jwt,
        private RefreshTokenRepository $refreshTokens,
        private EntityManagerInterface $em,
        private LoggerInterface $logger,
        private string $cookieDomain,
        private bool $secureCookies,
    ) {
    }

    /**
     * A fresh session for a user in a tenant.
     *
     * @return array{token: string, expiresIn: int, cookie: Cookie, tenantId: ?string}
     */
    #[InfrastructureWrite(reason: 'minting a session token; audited at the login/refresh level, not per token')]
    public function issue(User $user, ?Membership $membership, ?string $family = null): array
    {
        $tenantId = $membership?->tenantId();

        $token = $this->jwt->createFromPayload($user, [
            // The realm claim. Without it the manager firewall would accept this
            // token for any operator sharing the address (ADR-0007).
            TokenAudience::CLAIM => TokenAudience::App->value,
            'tid' => $tenantId,
            'role' => $membership?->role(),
            'roles' => $membership?->securityRoles() ?? ['ROLE_USER'],
        ]);

        [$raw, $entity] = $this->mintRefreshToken($user, $tenantId, $family);
        $this->em->persist($entity);

        return [
            'token' => $token,
            'expiresIn' => 900,
            'cookie' => $this->cookie($raw, $entity->expiresAt()),
            'tenantId' => $tenantId,
        ];
    }

    /**
     * Exchange a refresh token for a new session, rotating it.
     *
     * @return array{token: string, expiresIn: int, cookie: Cookie, tenantId: ?string, user: User}
     *
     * @throws AuthenticationException when the token is unknown, expired, or reused
     */
    #[InfrastructureWrite(reason: 'rotating a session token, including revoking a reused family')]
    public function rotate(Request $request, ?string $switchToTenantId = null): array
    {
        $raw = $request->cookies->get(self::COOKIE);
        if (!\is_string($raw) || $raw === '') {
            throw new AuthenticationException('No refresh token.');
        }

        $stored = $this->refreshTokens->byHash(hash('sha256', $raw));
        if ($stored === null) {
            throw new AuthenticationException('Unknown refresh token.');
        }

        // Reuse detection. A token already consumed means either an attacker is
        // replaying a stolen one, or the legitimate client rotated and the
        // attacker got there first. We cannot tell which, so the entire family
        // dies and both parties must log in again - inconvenient, and the only
        // safe answer.
        if ($stored->wasAlreadyUsed()) {
            $this->revokeFamily($stored->family());
            $this->logger->warning('auth.refresh.reuse_detected', [
                'user' => $stored->user()->getUserIdentifier(),
                'family' => $stored->family(),
            ]);
            $this->em->flush();

            throw new AuthenticationException('This session has been revoked.');
        }

        if (!$stored->isUsable()) {
            throw new AuthenticationException('This session has expired.');
        }

        $stored->markUsed();

        $user = $stored->user();
        if (!$user->isActive()) {
            throw new AuthenticationException('This account is not active.');
        }

        $tenantId = $switchToTenantId ?? $stored->tenantId();
        $membership = $tenantId !== null ? $user->membershipIn($tenantId) : null;

        if ($tenantId !== null && $membership === null) {
            throw new AuthenticationException('You are not a member of that tenant.');
        }

        // Same family: the chain from one login stays linked, so reuse anywhere
        // in it revokes all of it.
        $session = $this->issue($user, $membership, $stored->family());

        return [...$session, 'user' => $user];
    }

    /**
     * Rotate and commit, for callers that are not themselves inside a handler.
     *
     * @return array{token: string, expiresIn: int, cookie: Cookie, tenantId: ?string, user: User}
     */
    #[InfrastructureWrite(reason: 'committing a rotated session token')]
    public function rotateAndPersist(Request $request, ?string $switchToTenantId = null): array
    {
        $session = $this->rotate($request, $switchToTenantId);
        $this->em->flush();

        return $session;
    }

    public function revokeAllFor(User $user): void
    {
        foreach ($this->refreshTokens->forUser($user) as $token) {
            $token->revoke();
        }
    }

    public function revokeFamily(string $family): void
    {
        foreach ($this->refreshTokens->family($family) as $token) {
            $token->revoke();
        }
    }

    /** Logging out must also clear the cookie, or the browser keeps presenting a dead token. */
    public function clearCookie(Response $response): void
    {
        $response->headers->setCookie($this->cookie('', new \DateTimeImmutable('-1 day')));
    }

    /** @return array{0: string, 1: RefreshToken} */
    private function mintRefreshToken(User $user, ?string $tenantId, ?string $family): array
    {
        $raw = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $entity = new RefreshToken(
            hash('sha256', $raw),
            $user,
            (new \DateTimeImmutable())->add(new \DateInterval(self::REFRESH_TTL)),
            $tenantId,
            $family,
        );

        return [$raw, $entity];
    }

    private function cookie(string $value, \DateTimeImmutable $expires): Cookie
    {
        return Cookie::create(self::COOKIE)
            ->withValue($value)
            ->withExpires($expires)
            ->withPath('/api/auth')      // sent only to the endpoints that need it
            ->withDomain($this->cookieDomain !== '' ? $this->cookieDomain : null)
            ->withSecure($this->secureCookies)
            ->withHttpOnly(true)         // the whole point: JavaScript cannot read it
            ->withSameSite(Cookie::SAMESITE_STRICT);
    }
}
