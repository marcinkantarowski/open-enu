<?php

declare(strict_types=1);

namespace OpenEnu\Kernel\Security;

use Symfony\Component\HttpFoundation\Request;

/**
 * The claims that mark a session as impersonated, and how to read them.
 *
 * Kept in the kernel rather than the Manager module because two very different
 * places need to agree on them: the manager realm that MINTS such a token, and
 * the tenant realm that must refuse certain actions when it sees one. Neither
 * should know about the other (ADR-0016).
 */
final readonly class Impersonation
{
    /** Marks the token as an impersonation. */
    public const string CLAIM = 'imp';

    /** Who is really acting: `{sub: <managerId>, realm: 'manager'}`. */
    public const string ACTOR_CLAIM = 'act';

    public const string ATTRIBUTE = '_open_enu_impersonation';

    /** Deliberately short. A support session is a conversation, not a login. */
    public const int TTL_SECONDS = 900;

    /** @param array<string, mixed> $payload */
    public static function isImpersonated(array $payload): bool
    {
        return ($payload[self::CLAIM] ?? false) === true;
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array{sub: string, realm: string}|null
     */
    public static function actor(array $payload): ?array
    {
        $act = $payload[self::ACTOR_CLAIM] ?? null;

        if (!\is_array($act) || !\is_string($act['sub'] ?? null) || !\is_string($act['realm'] ?? null)) {
            return null;
        }

        return ['sub' => $act['sub'], 'realm' => $act['realm']];
    }

    /** @return array{sub: string, realm: string}|null */
    public static function fromRequest(Request $request): ?array
    {
        $stored = $request->attributes->get(self::ATTRIBUTE);

        return \is_array($stored) && \is_string($stored['sub'] ?? null) && \is_string($stored['realm'] ?? null)
            ? ['sub' => $stored['sub'], 'realm' => $stored['realm']]
            : null;
    }
}
