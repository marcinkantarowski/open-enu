<?php

declare(strict_types=1);

namespace OpenEnu\Kernel\Security;

/**
 * Which realm a token belongs to.
 *
 * The claim that makes two firewalls actually isolated (ADR-0007). Without it,
 * both verify against the same signing key, so a tenant token presented to
 * /api/manager passes verification and is then looked up by email in the manager
 * provider - and a user whose address matches an operator's becomes that
 * operator.
 */
enum TokenAudience: string
{
    /** Tenant users, on app.${DOMAIN}. */
    case App = 'app';

    /** Platform operators, on manager.${DOMAIN}. */
    case Manager = 'manager';

    /** Machine clients authenticating with a key. */
    case ApiKey = 'api_key';

    public const string CLAIM = 'aud';
}
