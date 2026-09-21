<?php

declare(strict_types=1);

namespace App\Module\Manager\Entity;

use App\Module\Manager\Repository\PlatformManagerRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use OpenEnu\Kernel\Contract\VersionedInterface;
use OpenEnu\Kernel\Doctrine\Type\EncryptedStringType;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Someone who operates the platform. Not a tenant user.
 *
 * A separate TABLE, not a role on `User`. That separation is the whole reason
 * the two realms can be isolated at all: with one table and one provider, the
 * only thing distinguishing an operator from a customer would be a column, and
 * a token would resolve against both (ADR-0007).
 *
 * The encrypted email uses the GLOBAL key - operators belong to no tenant, so a
 * tenant-derived key would make the row readable only by coincidence.
 */
#[ORM\Entity(repositoryClass: PlatformManagerRepository::class)]
#[ORM\Table(name: 'platform_manager')]
#[ORM\UniqueConstraint(name: 'uniq_manager_email_hash', columns: ['email_hash'])]
class PlatformManager implements UserInterface, PasswordAuthenticatedUserInterface, VersionedInterface
{
    /** Can do everything, including managing other operators. */
    public const string ROLE_SUPER = 'ROLE_PLATFORM_SUPER';

    /** Day-to-day operations: read tenants, suspend, impersonate. */
    public const string ROLE_OPERATOR = 'ROLE_PLATFORM_MANAGER';

    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\Column(type: EncryptedStringType::NAME)]
    private string $email;

    #[ORM\Column(type: Types::STRING, length: 64)]
    private string $emailHash;

    #[ORM\Column(type: Types::STRING)]
    private string $password = '';

    #[ORM\Column(type: EncryptedStringType::NAME, nullable: true)]
    private ?string $displayName = null;

    /** @var list<string> */
    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true])]
    private array $roles = [self::ROLE_OPERATOR];

    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $active = true;

    #[ORM\Version]
    #[ORM\Column(type: Types::INTEGER)]
    private int $version = 1;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastLoginAt = null;

    public function __construct(string $email, string $emailHash)
    {
        $this->id = Uuid::v7();
        $this->email = $email;
        $this->emailHash = $emailHash;
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

    public function isActive(): bool
    {
        return $this->active;
    }

    public function deactivate(): void
    {
        $this->active = false;
    }

    /** @return list<string> */
    public function getRoles(): array
    {
        // Never ROLE_USER: an operator must not satisfy a check written for a
        // customer. The two vocabularies are deliberately disjoint.
        return array_values(array_unique($this->roles));
    }

    /** @param list<string> $roles */
    public function setRoles(array $roles): void
    {
        $this->roles = $roles;
    }

    public function isSuper(): bool
    {
        return \in_array(self::ROLE_SUPER, $this->roles, true);
    }

    public function recordLogin(): void
    {
        $this->lastLoginAt = new \DateTimeImmutable();
    }

    public function version(): int
    {
        return $this->version;
    }

    public function getUserIdentifier(): string
    {
        return (string) $this->id;
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
            'roles' => $this->getRoles(),
            'active' => $this->active,
            'lastLoginAt' => $this->lastLoginAt?->format(\DATE_ATOM),
            'createdAt' => $this->createdAt->format(\DATE_ATOM),
        ];
    }
}
