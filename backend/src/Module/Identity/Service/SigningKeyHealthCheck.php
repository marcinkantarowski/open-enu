<?php

declare(strict_types=1);

namespace App\Module\Identity\Service;

use Lexik\Bundle\JWTAuthenticationBundle\Encoder\JWTEncoderInterface;
use OpenEnu\Kernel\Health\HealthCheckInterface;

/**
 * The JWT private key signs: it is there, readable by the process that serves
 * requests, and the passphrase opens it.
 *
 * Every login and every refresh signs a token, so without this the API is up
 * and nobody can get into it - which is what the first production image did:
 * the key was baked in as root-only, php-fpm runs as www-data, and every login
 * answered 500 behind a green health check. Signing one throwaway token proves
 * the whole path, not only that a file exists.
 */
final readonly class SigningKeyHealthCheck implements HealthCheckInterface
{
    public function __construct(private JWTEncoderInterface $encoder)
    {
    }

    public function name(): string
    {
        return 'jwt';
    }

    public function check(): void
    {
        $this->encoder->encode(['sub' => 'health', 'exp' => time() + 60]);
    }
}
