<?php

declare(strict_types=1);

namespace App\Module\Identity\Handler;

use App\Module\Identity\Command\ResetPassword;
use App\Module\Identity\Entity\SecurityToken;
use App\Module\Identity\Service\SecurityTokenIssuer;
use App\Module\Identity\Service\SessionIssuer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AsMessageHandler]
final readonly class ResetPasswordHandler
{
    public function __construct(
        private SecurityTokenIssuer $tokens,
        private UserPasswordHasherInterface $hasher,
        private SessionIssuer $sessions,
        private EntityManagerInterface $em,
    ) {
    }

    /** @return array<string, mixed> */
    public function __invoke(ResetPassword $command): array
    {
        $token = $this->tokens->redeem($command->rawToken, SecurityToken::PURPOSE_RESET_PASSWORD);
        $user = $token?->user();

        if ($user === null) {
            throw new BadRequestHttpException('This reset link is invalid or has expired.');
        }

        $user->setPassword($this->hasher->hashPassword($user, $command->plainPassword));

        // Every existing session dies with the password. A reset prompted by a
        // suspected compromise that leaves the intruder logged in has achieved
        // nothing.
        $this->sessions->revokeAllFor($user);
        $this->em->flush();

        return ['status' => 'reset', 'userId' => (string) $user->id()];
    }
}
