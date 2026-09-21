<?php

declare(strict_types=1);

namespace App\Module\Identity\Contract;

/**
 * Minting a session for a user, for the one caller allowed to ask.
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
}
