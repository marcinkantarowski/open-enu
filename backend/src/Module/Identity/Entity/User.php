<?php

declare(strict_types=1);

namespace App\Module\Identity\Entity;

use App\Module\Identity\Repository\UserRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use OpenEnu\Kernel\Contract\VersionedInterface;
use OpenEnu\Kernel\Doctrine\Type\EncryptedStringType;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Uid\Uuid;

/**
 * A person. Not a tenant member - that is Membership.
 *
 * A user exists once and belongs to several tenants, which is why this entity is
 * NOT tenant-scoped (ADR-0005). Retrofitting that later means rewriting every
 * query and every token, so it is paid for on day one.
 *
 * The email address is encrypted at rest. That makes it unsearchable, so a
 * deterministic `email_hash` sits beside it and is what login queries - the
 * pattern every encrypted-and-looked-up column follows.
 */
#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: 'identity_user')]
#[ORM\UniqueConstraint(name: 'uniq_user_email_hash', columns: ['email_hash'])]
#[ORM\Index(columns: ['status'], name: 'idx_user_status')]
class User implements UserInterface, PasswordAuthenticatedUserInterface, VersionedInterface
{
    public const string STATUS_UNVERIFIED = 'unverified';
    public const string STATUS_ACTIVE = 'active';
    public const string STATUS_DISABLED = 'disabled';

    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    /** Ciphertext at rest. Never query this column - query emailHash. */
    #[ORM\Column(type: EncryptedStringType::NAME)]
    private string $email;

    /**
     * SHA-256 of the normalised address. Equality only, which is all a login
     * needs, and it leaks nothing beyond "these two rows hold the same value".
     */
    #[ORM\Column(type: Types::STRING, length: 64)]
    private string $emailHash;

    #[ORM\Column(type: Types::STRING)]
    private string $password = '';

    #[ORM\Column(type: EncryptedStringType::NAME, nullable: true)]
    private ?string $displayName = null;

    #[ORM\Column(type: Types::STRING, length: 20)]
    private string $status = self::STATUS_UNVERIFIED;

    /** Outranks Accept-Language when resolving the response language. */
    #[ORM\Column(type: Types::STRING, length: 5, nullable: true)]
    private ?string $locale = null;

    /** Storage key, not a path. Resolved to an expiring URL on read. */
    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    private ?string $avatarKey = null;

    /** @var Collection<int, Membership> */
    #[ORM\OneToMany(targetEntity: Membership::class, mappedBy: 'user', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $memberships;

    #[ORM\Version]
    #[ORM\Column(type: Types::INTEGER)]
    private int $version = 1;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastLoginAt = null;

    /** @var list<string>|null transient - the current token's roles, never stored */
    private ?array $sessionRoles = null;

    /** Transient - the tenant this session is scoped to. */
    private ?string $sessionTenantId = null;

    public function __construct(string $email, string $emailHash)
    {
        $this->id = Uuid::v7();
        $this->email = $email;
        $this->emailHash = $emailHash;
        $this->memberships = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
    }

    public function id(): Uuid
    {
        return $this->id;
    }

    public function email(): string
    {
        return $this->email;
    }

    public function changeEmail(string $email, string $emailHash): void
    {
        $this->email = $email;
        $this->emailHash = $emailHash;
        // A changed address is unverified until proven, or changing it would be
        // a way to take over an address you do not control.
        $this->status = self::STATUS_UNVERIFIED;
    }

    public function emailHash(): string
    {
        return $this->emailHash;
    }

    public function getPassword(): string
    {
        return $this->password;
    }

    public function setPassword(string $hashed): void
    {
        $this->password = $hashed;
    }

    public function displayName(): ?string
    {
        return $this->displayName;
    }

    public function setDisplayName(?string $name): void
    {
        $this->displayName = $name;
    }

    public function status(): string
    {
        return $this->status;
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function verify(): void
    {
        if ($this->status === self::STATUS_UNVERIFIED) {
            $this->status = self::STATUS_ACTIVE;
        }
    }

    public function disable(): void
    {
        $this->status = self::STATUS_DISABLED;
    }

    public function locale(): ?string
    {
        return $this->locale;
    }

    public function setLocale(?string $locale): void
    {
        $this->locale = $locale;
    }

    public function avatarKey(): ?string
    {
        return $this->avatarKey;
    }

    public function setAvatarKey(?string $key): void
    {
        $this->avatarKey = $key;
    }

    /** @return Collection<int, Membership> */
    public function memberships(): Collection
    {
        return $this->memberships;
    }

    public function membershipIn(string $tenantId): ?Membership
    {
        foreach ($this->memberships as $membership) {
            if ($membership->tenantId() === $tenantId && $membership->isActive()) {
                return $membership;
            }
        }

        return null;
    }

    public function addMembership(Membership $membership): void
    {
        if (!$this->memberships->contains($membership)) {
            $this->memberships->add($membership);
        }
    }

    public function recordLogin(): void
    {
        $this->lastLoginAt = new \DateTimeImmutable();
    }

    public function lastLoginAt(): ?\DateTimeImmutable
    {
        return $this->lastLoginAt;
    }

    public function version(): int
    {
        return $this->version;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    // ── UserInterface ───────────────────────────────────────────────────────

    /**
     * The identifier Symfony stores in the token.
     *
     * The UUID, not the email: the email is encrypted, so using it would put a
     * decrypted address in every token and make the lookup depend on the
     * encryption key being available at authentication time.
     */
    public function getUserIdentifier(): string
    {
        return (string) $this->id;
    }

    /**
     * Roles for the CURRENT tenant, taken from the token - not from the user.
     *
     * A user may be an owner in one tenant and a member in another (ADR-0005),
     * so "the roles of a user" is not a well-formed question. The token carries
     * the answer for the session it belongs to, and UserProvider sets it here
     * when it resolves the payload.
     *
     * @return list<string>
     */
    public function getRoles(): array
    {
        return $this->sessionRoles ?? ['ROLE_USER'];
    }

    /**
     * Session-scoped, never persisted: this is the token's view of the user, and
     * it changes when they switch tenant.
     *
     * @param list<string> $roles
     */
    public function withSessionRoles(array $roles, ?string $tenantId): void
    {
        $this->sessionRoles = $roles;
        $this->sessionTenantId = $tenantId;
    }

    public function sessionTenantId(): ?string
    {
        return $this->sessionTenantId;
    }

    /** Nothing transient is held; the hash is the stored value. */
    #[\Deprecated]
    public function eraseCredentials(): void
    {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => (string) $this->id,
            'email' => $this->email,
            'displayName' => $this->displayName,
            'status' => $this->status,
            'locale' => $this->locale,
            'hasAvatar' => $this->avatarKey !== null,
            'version' => $this->version,
            'createdAt' => $this->createdAt->format(\DATE_ATOM),
        ];
    }
}
