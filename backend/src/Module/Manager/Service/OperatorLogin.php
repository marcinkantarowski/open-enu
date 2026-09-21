<?php

declare(strict_types=1);

namespace App\Module\Manager\Service;

use App\Module\Manager\Entity\PlatformManager;
use App\Module\Manager\Repository\PlatformManagerRepository;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use OpenEnu\Kernel\Attribute\InfrastructureWrite;
use OpenEnu\Kernel\Crypto\Encryptor;
use OpenEnu\Kernel\Security\TokenAudience;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Authenticating an operator and minting their realm token.
 *
 * Session machinery, like LoginService on the tenant side: not a command,
 * because auditing a token mint per login would bury the trail - and the
 * security log already records every attempt.
 */
final readonly class OperatorLogin
{
    public function __construct(
        private PlatformManagerRepository $managers,
        private Encryptor $encryptor,
        private UserPasswordHasherInterface $hasher,
        private JWTTokenManagerInterface $jwt,
        private EntityManagerInterface $em,
    ) {
    }

    /** @return array{token: string, expiresIn: int, manager: array<string, mixed>} */
    #[InfrastructureWrite(reason: 'recording a last-login stamp; the attempt itself is in the security log')]
    public function authenticate(string $email, string $password): array
    {
        $manager = $this->managers->byEmailHash($this->encryptor->hashForLookup($email));

        // One answer for every failure mode, as with tenant login: unknown
        // address, wrong password and deactivated account are indistinguishable.
        if ($manager === null
            || !$manager->isActive()
            || !$this->hasher->isPasswordValid($manager, $password)
        ) {
            throw new UnauthorizedHttpException('Bearer', 'Those credentials are not valid.');
        }

        $manager->recordLogin();
        $this->em->flush();

        return [
            'token' => $this->jwt->createFromPayload($manager, [
                // The claim that makes this token useless on /api, and a tenant
                // token useless here (ADR-0007).
                TokenAudience::CLAIM => TokenAudience::Manager->value,
                'roles' => $manager->getRoles(),
            ]),
            'expiresIn' => 900,
            'manager' => $manager->toArray(),
        ];
    }
}
