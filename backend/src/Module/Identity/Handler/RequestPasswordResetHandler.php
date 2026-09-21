<?php

declare(strict_types=1);

namespace App\Module\Identity\Handler;

use App\Module\Identity\Command\RequestPasswordReset;
use App\Module\Identity\Entity\SecurityToken;
use App\Module\Identity\Repository\UserRepository;
use App\Module\Identity\Service\IdentityMailer;
use App\Module\Identity\Service\SecurityTokenIssuer;
use Doctrine\ORM\EntityManagerInterface;
use OpenEnu\Kernel\Crypto\Encryptor;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class RequestPasswordResetHandler
{
    public function __construct(
        private UserRepository $users,
        private Encryptor $encryptor,
        private SecurityTokenIssuer $tokens,
        private IdentityMailer $mailer,
        private EntityManagerInterface $em,
    ) {
    }

    /** @return array<string, mixed> */
    public function __invoke(RequestPasswordReset $command): array
    {
        $user = $this->users->byEmailHash($this->encryptor->hashForLookup($command->email));

        if ($user !== null && $user->isActive()) {
            [$token, $raw] = $this->tokens->issue(SecurityToken::PURPOSE_RESET_PASSWORD, $user);
            $this->em->persist($token);
            $this->em->flush();
            $this->mailer->sendPasswordReset($command->email, $raw, $user->locale() ?? 'en');
        }

        // The same answer either way. Otherwise this endpoint enumerates users
        // for anyone who asks - and the audit entry above records that someone
        // asked, which is the part worth keeping.
        return ['status' => 'sent'];
    }
}
