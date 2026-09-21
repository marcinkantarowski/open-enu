<?php

declare(strict_types=1);

namespace App\Module\Identity\Controller\Api;

use App\Module\Identity\Command\RegisterUser;
use App\Module\Identity\Command\RequestPasswordReset;
use App\Module\Identity\Command\ResetPassword;
use App\Module\Identity\Command\VerifyEmail;
use App\Module\Identity\Entity\User;
use App\Module\Identity\Service\LoginService;
use App\Module\Identity\Service\SessionIssuer;
use App\Module\Identity\Service\SessionPayload;
use OpenEnu\Kernel\Command\CommandBusInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Exception\AuthenticationException;

/**
 * Everything that establishes or ends a session.
 *
 * All of it is public, so all of it is rate-limited: these are the endpoints
 * reachable without credentials, and the ones that leak whether an address
 * exists if they are allowed to answer differently.
 *
 * Thin by rule - no EntityManager, no flush. State changes worth auditing go
 * through the command bus; session machinery goes through LoginService.
 */
final readonly class AuthController
{
    public function __construct(
        private CommandBusInterface $commands,
        private LoginService $logins,
        private SessionIssuer $sessions,
        private SessionPayload $payload,
        #[Target('authLimiter')] private RateLimiterFactoryInterface $authLimiter,
        #[Target('registrationLimiter')] private RateLimiterFactoryInterface $registrationLimiter,
        #[Target('refreshLimiter')] private RateLimiterFactoryInterface $refreshLimiter,
    ) {
    }

    #[Route('/api/auth/register', name: 'auth_register', methods: ['POST'])]
    public function register(Request $request): JsonResponse
    {
        $this->limit($this->registrationLimiter, $request);
        $body = $this->body($request);

        $result = $this->commands->dispatch(new RegisterUser(
            email: $this->requireString($body, 'email'),
            plainPassword: $this->requirePassword($body),
            tenantName: $this->requireString($body, 'tenantName'),
            displayName: \is_string($body['displayName'] ?? null) ? $body['displayName'] : null,
            locale: \is_string($body['locale'] ?? null) ? $body['locale'] : 'en',
        ));

        // 202, not 201: the account exists but is unusable until the address is
        // verified, and "created" would invite the client to try logging in.
        return new JsonResponse($result, Response::HTTP_ACCEPTED);
    }

    #[Route('/api/auth/verify', name: 'auth_verify', methods: ['POST'])]
    public function verify(Request $request): JsonResponse
    {
        $this->limit($this->authLimiter, $request);

        return new JsonResponse($this->commands->dispatch(
            new VerifyEmail($this->requireString($this->body($request), 'token')),
        ));
    }

    #[Route('/api/auth/login', name: 'auth_login', methods: ['POST'])]
    public function login(Request $request): JsonResponse
    {
        $this->limit($this->authLimiter, $request);
        $body = $this->body($request);

        $session = $this->logins->authenticate(
            email: $this->requireString($body, 'email'),
            password: $this->requireString($body, 'password'),
            tenantId: \is_string($body['tenantId'] ?? null) ? $body['tenantId'] : null,
        );

        return $this->sessionResponse($session, $session['user']);
    }

    #[Route('/api/auth/refresh', name: 'auth_refresh', methods: ['POST'])]
    public function refresh(Request $request): JsonResponse
    {
        $this->limit($this->refreshLimiter, $request);

        try {
            $session = $this->sessions->rotateAndPersist($request);
        } catch (AuthenticationException $e) {
            throw new UnauthorizedHttpException('Bearer', $e->getMessage(), $e);
        }

        return $this->sessionResponse($session, $session['user']);
    }

    #[Route('/api/auth/switch-tenant', name: 'auth_switch_tenant', methods: ['POST'])]
    public function switchTenant(Request $request): JsonResponse
    {
        $this->limit($this->refreshLimiter, $request);

        // Switching re-issues rather than mutating anything stored: the tenant
        // lives in the token, so a switch is a new token (ADR-0005).
        try {
            $session = $this->sessions->rotateAndPersist(
                $request,
                $this->requireString($this->body($request), 'tenantId'),
            );
        } catch (AuthenticationException $e) {
            throw new UnauthorizedHttpException('Bearer', $e->getMessage(), $e);
        }

        return $this->sessionResponse($session, $session['user']);
    }

    #[Route('/api/auth/logout', name: 'auth_logout', methods: ['POST'])]
    public function logout(Request $request): JsonResponse
    {
        $this->logins->endSession($request);

        $response = new JsonResponse(['status' => 'logged_out']);
        // Cleared regardless: otherwise the browser keeps presenting a dead token.
        $this->sessions->clearCookie($response);

        return $response;
    }

    #[Route('/api/auth/forgot-password', name: 'auth_forgot_password', methods: ['POST'])]
    public function forgotPassword(Request $request): JsonResponse
    {
        $this->limit($this->registrationLimiter, $request);

        return new JsonResponse($this->commands->dispatch(
            new RequestPasswordReset($this->requireString($this->body($request), 'email')),
        ));
    }

    #[Route('/api/auth/reset-password', name: 'auth_reset_password', methods: ['POST'])]
    public function resetPassword(Request $request): JsonResponse
    {
        $this->limit($this->authLimiter, $request);
        $body = $this->body($request);

        return new JsonResponse($this->commands->dispatch(new ResetPassword(
            rawToken: $this->requireString($body, 'token'),
            plainPassword: $this->requirePassword($body),
        )));
    }

    // ── helpers ─────────────────────────────────────────────────────────────

    /** @param array{token: string, expiresIn: int, cookie: Cookie, tenantId: ?string} $session */
    private function sessionResponse(array $session, User $user): JsonResponse
    {
        $response = new JsonResponse([
            // The access token goes in the BODY for the client to keep in
            // memory. Deliberately not a cookie: a cookie is sent automatically
            // and would need CSRF protection (ADR-0006).
            'token' => $session['token'],
            'expiresIn' => $session['expiresIn'],
            'tenantId' => $session['tenantId'],
            // One shape for every endpoint that establishes a session - login,
            // refresh, switch-tenant and impersonation - so the client has one
            // code path for "I now have a session" rather than four that differ
            // in small ways nobody wrote down.
            ...$this->payload->for($user, $session['tenantId']),
        ]);

        $response->headers->setCookie($session['cookie']);

        return $response;
    }

    /** @return array<string, mixed> */
    private function body(Request $request): array
    {
        $decoded = json_decode($request->getContent() ?: '{}', true);

        return \is_array($decoded) ? $decoded : throw new BadRequestHttpException('Expected a JSON object.');
    }

    /** @param array<string, mixed> $body */
    private function requireString(array $body, string $key): string
    {
        $value = $body[$key] ?? null;

        return \is_string($value) && trim($value) !== ''
            ? trim($value)
            : throw new BadRequestHttpException(sprintf('"%s" is required.', $key));
    }

    /** @param array<string, mixed> $body */
    private function requirePassword(array $body): string
    {
        $password = $body['password'] ?? null;

        if (!\is_string($password) || mb_strlen($password) < 12) {
            // Length over composition rules: it is the only requirement that
            // reliably increases entropy rather than pushing people to Passw0rd!
            throw new BadRequestHttpException('The password must be at least 12 characters.');
        }

        return $password;
    }

    private function limit(RateLimiterFactoryInterface $factory, Request $request): void
    {
        $limit = $factory->create($request->getClientIp() ?? 'unknown')->consume();

        if (!$limit->isAccepted()) {
            throw new TooManyRequestsHttpException(
                max(1, $limit->getRetryAfter()->getTimestamp() - time()),
                'Too many attempts. Try again shortly.',
            );
        }
    }
}
