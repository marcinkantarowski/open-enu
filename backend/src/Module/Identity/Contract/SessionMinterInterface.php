<?php

declare(strict_types=1);

namespace App\Module\Identity\Contract;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * Minting a session for a user another module vouches for.
 *
 * Impersonation is the single legitimate crossing between the two realms
 * (ADR-0008), and it is split deliberately: the Manager module decides WHETHER
 * an operator may impersonate; this module decides WHAT a session for a user
 * looks like. Neither knows the other's rules, and Manager never touches a
 * `User`.
 *
 * The returned token is intentionally NOT refreshable - no refresh cookie is
 * issued - so a support session expires rather than renewing itself quietly.
 */
interface SessionMinterInterface
{
    /**
     * @param array{sub: string, realm: string} $actor who is really behind the session
     *
     * @return array{token: string, expiresIn: int, tenantId: string, user: array<string, mixed>,
     *     memberships: list<array<string, mixed>>, role: ?string, permissions: list<string>,
     *     viewingAs: array<string, mixed>}
     *
     * @throws \RuntimeException when the user is not a member of that tenant, or is inactive
     */
    public function mintImpersonationSession(string $userId, string $tenantId, array $actor): array;

    /**
     * An ordinary session for a person another module has authenticated.
     *
     * Identity checks an email and a password. A project may know its people
     * by other means - a single sign-on provider, a link sent by mail,
     * credentials carried over from a system it replaced - and a module that
     * checks those decides only WHO the person is. What a session looks like
     * stays here: the login's response, token and session in the body and the
     * refresh token in a cookie, so the browser needs no second code path.
     * In the given tenant, or the user's best membership when none is given.
     *
     * The caller owns refusing a wrong guess: rate-limit its endpoint and
     * answer every failure the same way.
     *
     * @throws UnauthorizedHttpException when the user does not exist, is not
     *                                   active, or is not a member of that tenant
     */
    public function startSession(string $userId, ?string $tenantId): Response;
}
