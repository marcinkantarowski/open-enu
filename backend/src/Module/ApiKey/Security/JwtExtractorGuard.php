<?php

declare(strict_types=1);

namespace App\Module\ApiKey\Security;

use App\Module\ApiKey\Entity\ApiKey;
use Lexik\Bundle\JWTAuthenticationBundle\TokenExtractor\TokenExtractorInterface;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\HttpFoundation\Request;

/**
 * Stops the JWT authenticator from claiming an API key.
 *
 * Both credentials arrive as `Authorization: Bearer …`, and Lexik's extractor
 * accepts any bearer - so it grabs `sk_…`, fails to parse it as a JWT, and
 * returns 401 before the key authenticator is consulted. Which of the two wins
 * then depends on the order Symfony happens to compile them in, which is not a
 * thing to depend on.
 *
 * Decorating the extractor makes them non-overlapping by construction: a bearer
 * carrying the key prefix is invisible to JWT authentication, so exactly one
 * authenticator can ever claim a given request.
 */
#[AsDecorator(decorates: 'lexik_jwt_authentication.extractor.authorization_header_extractor')]
final readonly class JwtExtractorGuard implements TokenExtractorInterface
{
    public function __construct(private TokenExtractorInterface $inner)
    {
    }

    public function extract(Request $request): string|false
    {
        $token = $this->inner->extract($request);

        if (\is_string($token) && str_starts_with($token, ApiKey::PREFIX)) {
            return false;
        }

        return $token;
    }
}
