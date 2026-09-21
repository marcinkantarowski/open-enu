<?php

declare(strict_types=1);

namespace App\Module\ApiKey\Entity;

use App\Module\ApiKey\Repository\ApiKeyRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use OpenEnu\Kernel\Contract\TenantScopedInterface;
use Symfony\Component\Uid\Uuid;

/**
 * A machine credential: third principal alongside users and operators.
 *
 * Without one, a "headless API" is browser-only - every integration ends up
 * sharing a human's password, which cannot be scoped, cannot be rotated without
 * breaking that person, and attributes every action to them.
 *
 * Design choices that matter:
 *  • **Hashed at rest**, like every other credential here. The prefix is kept in
 *    clear so a key can be identified in a list without revealing it.
 *  • **A permission SUBSET**, never "everything the creator can do". An
 *    integration that reads invoices should not be able to delete users because
 *    an admin created it.
 *  • **`lastUsedAt`**, so a key nobody uses can be found and revoked. Unused
 *    credentials are how old integrations become breaches.
 */
#[ORM\Entity(repositoryClass: ApiKeyRepository::class)]
#[ORM\Table(name: 'api_key')]
#[ORM\UniqueConstraint(name: 'uniq_api_key_hash', columns: ['key_hash'])]
#[ORM\Index(columns: ['tenant_id'], name: 'idx_api_key_tenant')]
class ApiKey implements TenantScopedInterface
{
    /** Identifies the credential type on sight, in a log or a leaked file. */
    public const string PREFIX = 'sk_';

    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\Column(name: 'tenant_id', type: 'uuid')]
    private Uuid $tenantId;

    #[ORM\Column(type: Types::STRING, length: 100)]
    private string $name;

    #[ORM\Column(type: Types::STRING, length: 64)]
    private string $keyHash;

    /** First 12 characters, shown in listings so a key is recognisable. */
    #[ORM\Column(type: Types::STRING, length: 16)]
    private string $keyPrefix;

    /**
     * Exactly what this key may do. An empty list grants nothing - fail closed,
     * so a key created without permissions is useless rather than unlimited.
     *
     * @var list<string>
     */
    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true])]
    private array $permissions = [];

    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $revoked = false;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $expiresAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastUsedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    /** @param list<string> $permissions */
    public function __construct(string $tenantId, string $name, string $keyHash, string $keyPrefix, array $permissions)
    {
        $this->id = Uuid::v7();
        $this->tenantId = Uuid::fromString($tenantId);
        $this->name = $name;
        $this->keyHash = $keyHash;
        $this->keyPrefix = $keyPrefix;
        $this->permissions = array_values($permissions);
        $this->createdAt = new \DateTimeImmutable();
    }

    public function id(): Uuid
    {
        return $this->id;
    }

    public function tenantId(): string
    {
        return (string) $this->tenantId;
    }

    public function name(): string
    {
        return $this->name;
    }

    /** @return list<string> */
    public function permissions(): array
    {
        return $this->permissions;
    }

    public function grants(string $permission): bool
    {
        return \in_array($permission, $this->permissions, true);
    }

    public function isUsable(): bool
    {
        return !$this->revoked && ($this->expiresAt === null || $this->expiresAt > new \DateTimeImmutable());
    }

    public function revoke(): void
    {
        $this->revoked = true;
    }

    public function recordUse(): void
    {
        $this->lastUsedAt = new \DateTimeImmutable();
    }

    public function expiresAt(): ?\DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function setExpiresAt(?\DateTimeImmutable $at): void
    {
        $this->expiresAt = $at;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => (string) $this->id,
            'name' => $this->name,
            // Never the key itself: it exists in clear exactly once, at creation.
            'prefix' => $this->keyPrefix,
            'permissions' => $this->permissions,
            'revoked' => $this->revoked,
            'expiresAt' => $this->expiresAt?->format(\DATE_ATOM),
            'lastUsedAt' => $this->lastUsedAt?->format(\DATE_ATOM),
            'createdAt' => $this->createdAt->format(\DATE_ATOM),
        ];
    }
}
