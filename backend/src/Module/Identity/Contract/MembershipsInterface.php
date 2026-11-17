<?php

declare(strict_types=1);

namespace App\Module\Identity\Contract;

/**
 * A tenant's memberships, for the module that administers them (Member).
 *
 * Methods that change something change it in memory only, and those that
 * create rows return them as `unsaved`: writes happen in a command handler and
 * nowhere else (ADR-0017), and the handler is the caller's. It persists what it
 * is handed and flushes, inside the audited command.
 *
 * @phpstan-type Member array{id: string, userId: string, email: string, displayName: ?string,
 *     role: string, unitIds: list<string>|null, status: string, joinedAt: string, version: int}
 */
interface MembershipsInterface
{
    /** @return list<Member> every membership of the tenant, invited and suspended included */
    public function inTenant(string $tenantId): array;

    /** @return Member|null */
    public function find(string $tenantId, string $membershipId): ?array;

    /**
     * @param list<string>|null $unitIds         null: the whole tenant
     * @param int|null          $expectedVersion the version the caller read; a 409 when it moved on
     */
    public function assign(string $tenantId, string $membershipId, string $role, ?array $unitIds, ?int $expectedVersion = null): void;

    public function suspend(string $tenantId, string $membershipId): void;

    /** Lifts a suspension. An invitation is accepted by its invitee, never activated for them. */
    public function reactivate(string $tenantId, string $membershipId): void;

    /**
     * An invited membership - and the account, if the address has none yet -
     * plus a single-use token for the link. Asking again re-issues the token.
     *
     * @param list<string>|null $unitIds
     *
     * @return array{membershipId: string, token: string, unsaved: list<object>}
     */
    public function invite(string $tenantId, string $email, string $role, ?array $unitIds): array;

    /** After the handler has flushed: a failed write never mails a link to nothing. */
    public function mailInvitation(string $tenantId, string $email, string $rawToken): void;

    /**
     * What an invitation link is for, without using it up.
     *
     * @return array{tenantId: string, tenantName: string, email: string, needsPassword: bool}|null
     */
    public function describeInvitation(string $rawToken): ?array;

    /**
     * Use the link: the membership becomes active, a new account gets its
     * password and counts as verified - the link arrived at the address.
     *
     * @return array{tenantId: string, membershipId: string, email: string}
     */
    public function acceptInvitation(string $rawToken, ?string $plainPassword, ?string $displayName): array;
}
