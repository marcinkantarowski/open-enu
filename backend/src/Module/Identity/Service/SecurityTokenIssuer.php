<?php

declare(strict_types=1);

namespace App\Module\Identity\Service;

use App\Module\Identity\Entity\SecurityToken;
use App\Module\Identity\Entity\User;
use App\Module\Identity\Repository\SecurityTokenRepository;

/**
 * Mints and redeems single-use tokens.
 *
 * The raw value is returned exactly once, to be put in an email, and is never
 * stored - only its SHA-256. That is why a lost reset link cannot be recovered
 * by support, which is the correct answer.
 *
 * SHA-256 rather than a password hash: the token is 256 bits of entropy from a
 * CSPRNG, so there is nothing to brute-force, and the lookup has to be fast and
 * indexable.
 */
final readonly class SecurityTokenIssuer
{
    public function __construct(private SecurityTokenRepository $tokens)
    {
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array{0: SecurityToken, 1: string} the entity and the RAW token
     */
    public function issue(string $purpose, ?User $user, array $payload = [], ?string $tenantId = null): array
    {
        $raw = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');

        return [new SecurityToken($this->hash($raw), $purpose, $user, $payload, $tenantId), $raw];
    }

    /**
     * Redeem a token, or fail.
     *
     * Consumption happens here rather than at the call site, so a caller cannot
     * validate a token, act on it, and forget to burn it.
     */
    public function redeem(string $raw, string $purpose): ?SecurityToken
    {
        $token = $this->tokens->byHash($this->hash($raw), $purpose);

        if ($token === null || !$token->isUsable()) {
            return null;
        }

        $token->consume();

        return $token;
    }

    public function hash(string $raw): string
    {
        return hash('sha256', $raw);
    }
}
