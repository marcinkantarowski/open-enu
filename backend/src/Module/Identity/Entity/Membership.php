<?php

declare(strict_types=1);

namespace App\Module\Identity\Entity;

use App\Module\Identity\Repository\MembershipRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * A user's place in one tenant, with one role.
 *
 * This is the entity that makes "a user belongs to several tenants" true. The
 * JWT carries the *current* tenant and the role for that membership; switching
 * tenant re-issues the token rather than changing anything stored.
 *
 * `tenantId` is a plain indexed UUID with no foreign key - cross-module FKs are
 * a build failure (ADR-0002). Integrity comes from the TenantDeleted listener.
 */
#[ORM\Entity(repositoryClass: MembershipRepository::class)]
#[ORM\Table(name: 'identity_membership')]
#[ORM\UniqueConstraint(name: 'uniq_membership_user_tenant', columns: ['user_id', 'tenant_id'])]
#[ORM\Index(columns: ['tenant_id'], name: 'idx_membership_tenant')]
class Membership
{
    public const string ROLE_OWNER = 'owner';
    public const string ROLE_ADMIN = 'admin';
    public const string ROLE_MEMBER = 'member';

    public const string STATUS_ACTIVE = 'active';
    public const string STATUS_SUSPENDED = 'suspended';

    /** Highest first - used to pick a default tenant at login. */
    private const array ROLE_RANK = [self::ROLE_OWNER => 3, self::ROLE_ADMIN => 2, self::ROLE_MEMBER => 1];

    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: User::class, inversedBy: 'memberships')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(type: 'uuid')]
    private Uuid $tenantId;

    #[ORM\Column(type: Types::STRING, length: 20)]
    private string $role;

    #[ORM\Column(type: Types::STRING, length: 20)]
    private string $status = self::STATUS_ACTIVE;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(User $user, string $tenantId, string $role = self::ROLE_MEMBER)
    {
        $this->id = Uuid::v7();
        $this->user = $user;
        $this->tenantId = Uuid::fromString($tenantId);
        $this->role = $role;
        $this->createdAt = new \DateTimeImmutable();
        $user->addMembership($this);
    }

    public function id(): Uuid
    {
        return $this->id;
    }

    public function user(): User
    {
        return $this->user;
    }

    public function tenantId(): string
    {
        return (string) $this->tenantId;
    }

    public function role(): string
    {
        return $this->role;
    }

    public function changeRole(string $role): void
    {
        $this->role = $role;
    }

    public function rank(): int
    {
        return self::ROLE_RANK[$this->role] ?? 0;
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function suspend(): void
    {
        $this->status = self::STATUS_SUSPENDED;
    }

    /**
     * Symfony roles for this membership. Uppercased and prefixed, because that
     * is what `IS_GRANTED` expects; the stored value stays lowercase so it reads
     * naturally in the API.
     *
     * @return list<string>
     */
    public function securityRoles(): array
    {
        return match ($this->role) {
            self::ROLE_OWNER => ['ROLE_USER', 'ROLE_MEMBER', 'ROLE_ADMIN', 'ROLE_OWNER'],
            self::ROLE_ADMIN => ['ROLE_USER', 'ROLE_MEMBER', 'ROLE_ADMIN'],
            default => ['ROLE_USER', 'ROLE_MEMBER'],
        };
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => (string) $this->id,
            'tenantId' => (string) $this->tenantId,
            'role' => $this->role,
            'status' => $this->status,
        ];
    }
}
