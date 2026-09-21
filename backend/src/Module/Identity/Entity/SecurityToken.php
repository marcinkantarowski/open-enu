<?php

declare(strict_types=1);

namespace App\Module\Identity\Entity;

use App\Module\Identity\Repository\SecurityTokenRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * A single-use, expiring token: email verification, password reset, invitation.
 *
 * Three properties, each of which is a real vulnerability when missing:
 *
 *  • **Hashed at rest.** The raw token exists only in the email. A database
 *    leak - or a backup, or a support query - otherwise hands out working
 *    password resets for every pending request.
 *  • **Single use.** Consumed on first use, so a token sitting in a forwarded
 *    email or a browser history cannot be replayed.
 *  • **Expiring.** A reset link that works forever is a permanent credential.
 */
#[ORM\Entity(repositoryClass: SecurityTokenRepository::class)]
#[ORM\Table(name: 'identity_security_token')]
#[ORM\UniqueConstraint(name: 'uniq_security_token_hash', columns: ['token_hash'])]
#[ORM\Index(columns: ['purpose', 'expires_at'], name: 'idx_security_token_purge')]
class SecurityToken
{
    public const string PURPOSE_VERIFY_EMAIL = 'verify_email';
    public const string PURPOSE_RESET_PASSWORD = 'reset_password';
    public const string PURPOSE_INVITATION = 'invitation';

    /** Chosen per purpose: longer-lived links are more likely to be forwarded. */
    public const array TTL = [
        self::PURPOSE_VERIFY_EMAIL => 'PT24H',
        self::PURPOSE_RESET_PASSWORD => 'PT1H',
        self::PURPOSE_INVITATION => 'P7D',
    ];

    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\Column(type: Types::STRING, length: 64)]
    private string $tokenHash;

    #[ORM\Column(type: Types::STRING, length: 32)]
    private string $purpose;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?User $user;

    /** For an invitation, the tenant being joined. */
    #[ORM\Column(type: 'uuid', nullable: true)]
    private ?Uuid $tenantId = null;

    /** @var array<string, mixed> */
    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true])]
    private array $payload = [];

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $expiresAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $consumedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    /** @param array<string, mixed> $payload */
    public function __construct(string $tokenHash, string $purpose, ?User $user, array $payload = [], ?string $tenantId = null)
    {
        $this->id = Uuid::v7();
        $this->tokenHash = $tokenHash;
        $this->purpose = $purpose;
        $this->user = $user;
        $this->payload = $payload;
        $this->tenantId = $tenantId !== null ? Uuid::fromString($tenantId) : null;
        $this->createdAt = new \DateTimeImmutable();
        $this->expiresAt = $this->createdAt->add(new \DateInterval(self::TTL[$purpose] ?? 'PT1H'));
    }

    public function id(): Uuid
    {
        return $this->id;
    }

    public function purpose(): string
    {
        return $this->purpose;
    }

    public function user(): ?User
    {
        return $this->user;
    }

    public function tenantId(): ?string
    {
        return $this->tenantId !== null ? (string) $this->tenantId : null;
    }

    /** @return array<string, mixed> */
    public function payload(): array
    {
        return $this->payload;
    }

    public function isUsable(): bool
    {
        return $this->consumedAt === null && $this->expiresAt > new \DateTimeImmutable();
    }

    public function consume(): void
    {
        $this->consumedAt = new \DateTimeImmutable();
    }

    public function expiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }
}
