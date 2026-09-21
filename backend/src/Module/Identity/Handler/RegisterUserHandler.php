<?php

declare(strict_types=1);

namespace App\Module\Identity\Handler;

use App\Module\Identity\Command\RegisterUser;
use App\Module\Identity\Entity\Membership;
use App\Module\Identity\Entity\SecurityToken;
use App\Module\Identity\Entity\User;
use App\Module\Identity\Repository\UserRepository;
use App\Module\Identity\Service\IdentityMailer;
use App\Module\Identity\Service\SecurityTokenIssuer;
use App\Module\Tenant\Contract\TenantProvisionerInterface;
use Doctrine\ORM\EntityManagerInterface;
use OpenEnu\Kernel\Crypto\Encryptor;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\String\Slugger\SluggerInterface;

/**
 * Self-service signup: a user and the tenant they own.
 *
 * Both start unverified. The tenant only becomes usable once the address is
 * proven, and unverified tenants are purged after 7 days - without that, an open
 * signup form is a tenant-spam endpoint.
 */
#[AsMessageHandler]
final readonly class RegisterUserHandler
{
    public function __construct(
        private UserRepository $users,
        private EntityManagerInterface $em,
        private Encryptor $encryptor,
        private UserPasswordHasherInterface $hasher,
        private SecurityTokenIssuer $tokens,
        private IdentityMailer $mailer,
        private TenantProvisionerInterface $tenants,
        private SluggerInterface $slugger,
    ) {
    }

    /** @return array<string, mixed> */
    public function __invoke(RegisterUser $command): array
    {
        $emailHash = $this->encryptor->hashForLookup($command->email);

        // The unique index on email_hash is the real guard; this produces a
        // civil error instead of a constraint violation.
        if ($this->users->byEmailHash($emailHash) !== null) {
            throw new ConflictHttpException('An account with that address already exists.');
        }

        $tenant = $this->tenants->create(
            slug: $this->uniqueSlug($command->tenantName),
            name: $command->tenantName,
            defaultLocale: $command->locale,
        );

        $user = new User($command->email, $emailHash);
        $user->setPassword($this->hasher->hashPassword($user, $command->plainPassword));
        $user->setDisplayName($command->displayName);
        $user->setLocale($command->locale);

        // The first user owns the tenant they created.
        new Membership($user, (string) $tenant['id'], Membership::ROLE_OWNER);

        [$token, $raw] = $this->tokens->issue(
            SecurityToken::PURPOSE_VERIFY_EMAIL,
            $user,
            ['tenantId' => $tenant['id']],
        );

        $this->em->persist($user);
        $this->em->persist($token);
        $this->em->flush();

        // Sent after the flush, so a failed write never produces a link to a
        // user that does not exist.
        $this->mailer->sendVerification($command->email, $raw, $command->locale);

        return [
            'userId' => (string) $user->id(),
            'tenantId' => $tenant['id'],
            'status' => $user->status(),
        ];
    }

    private function uniqueSlug(string $name): string
    {
        $base = strtolower($this->slugger->slug($name)->toString());
        $base = substr($base !== '' ? $base : 'tenant', 0, 40);

        // The slug appears in storage keys and URLs, so a collision is not
        // recoverable by renaming later. Suffix rather than fail.
        return $base . '-' . bin2hex(random_bytes(3));
    }
}
