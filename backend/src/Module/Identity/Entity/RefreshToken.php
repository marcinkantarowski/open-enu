<?php

declare(strict_types=1);

namespace App\Module\Identity\Entity;

use App\Module\Identity\Repository\RefreshTokenRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * The long-lived half of a session, stored so it can be revoked.
 *
 * Hashed at rest for the same reason as every other token here: a database leak
 * otherwise hands out live sessions.
 *
 * **Rotated on every use.** The old token is marked used and replaced. That is
 * what makes theft detectable - if a stolen token is redeemed after the real
 * user has already rotated it, the attempt hits an already-used row, and the
 * whole family is revoked on the assumption that one of the two parties is an
 * attacker.
 */
#[ORM\Entity(repositoryClass: RefreshTokenRepository::class)]
#[ORM\Table(name: 'identity_refresh_token')]
#[ORM\UniqueConstraint(name: 'uniq_refresh_token_hash', columns: ['token_hash'])]
#[ORM\Index(columns: ['family'], name: 'idx_refresh_family')]
class RefreshToken
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\Column(type: Types::STRING, length: 64)]
    private string $tokenHash;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    /**
     * All tokens descended from one login share a family, so detecting reuse can
     * revoke the whole chain rather than just the stolen link.
     */
    #[ORM\Column(type: 'uuid')]
    private Uuid $family;

    /** The tenant this session was scoped to when it was issued. */
    #[ORM\Column(type: 'uuid', nullable: true)]
    private ?Uuid $tenantId = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $expiresAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $usedAt = null;

    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $revoked = false;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(string $tokenHash, User $user, \DateTimeImmutable $expiresAt, ?string $tenantId, ?string $family = null)
    {
        $this->id = Uuid::v7();
        $this->tokenHash = $tokenHash;
        $this->user = $user;
        $this->expiresAt = $expiresAt;
        $this->tenantId = $tenantId !== null ? Uuid::fromString($tenantId) : null;
        $this->family = $family !== null ? Uuid::fromString($family) : Uuid::v7();
        $this->createdAt = new \DateTimeImmutable();
    }

    public function id(): Uuid
    {
        return $this->id;
    }

    public function user(): User
    {
        return $this->user;
    }

    public function family(): string
    {
        return (string) $this->family;
    }

    public function tenantId(): ?string
    {
        return $this->tenantId !== null ? (string) $this->tenantId : null;
    }

    public function isUsable(): bool
    {
        return !$this->revoked && $this->usedAt === null && $this->expiresAt > new \DateTimeImmutable();
    }

    public function wasAlreadyUsed(): bool
    {
        return $this->usedAt !== null;
    }

    public function markUsed(): void
    {
        $this->usedAt = new \DateTimeImmutable();
    }

    public function revoke(): void
    {
        $this->revoked = true;
    }

    public function expiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }
}
