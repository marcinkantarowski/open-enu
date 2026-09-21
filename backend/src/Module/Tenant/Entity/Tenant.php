<?php

declare(strict_types=1);

namespace App\Module\Tenant\Entity;

use App\Module\Tenant\Repository\TenantRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use OpenEnu\Kernel\Contract\VersionedInterface;
use Symfony\Component\Uid\Uuid;

/**
 * A customer account: the scope everything else is filtered by.
 *
 * Deliberately NOT tenant-scoped itself - it is the thing being scoped to, and
 * making it scoped would mean a tenant could only be read once you already knew
 * which tenant you were. Reading tenants is instead restricted by the manager
 * firewall and by TenantOwnedVoter.
 */
#[ORM\Entity(repositoryClass: TenantRepository::class)]
#[ORM\Table(name: 'tenant')]
#[ORM\Index(columns: ['status'], name: 'idx_tenant_status')]
class Tenant implements VersionedInterface
{
    public const string STATUS_PENDING = 'pending';
    public const string STATUS_ACTIVE = 'active';
    public const string STATUS_SUSPENDED = 'suspended';

    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    /** URL-safe identifier. Unique, immutable once set - it appears in storage keys. */
    #[ORM\Column(type: Types::STRING, length: 64, unique: true)]
    private string $slug;

    #[ORM\Column(type: Types::STRING, length: 200)]
    private string $name;

    #[ORM\Column(type: Types::STRING, length: 20)]
    private string $status = self::STATUS_PENDING;

    #[ORM\Column(type: Types::STRING, length: 40)]
    private string $plan = 'free';

    /** Default language for users who have expressed no preference. */
    #[ORM\Column(type: Types::STRING, length: 5)]
    private string $defaultLocale = 'en';

    #[ORM\Version]
    #[ORM\Column(type: Types::INTEGER)]
    private int $version = 1;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $activatedAt = null;

    public function __construct(string $slug, string $name)
    {
        $this->id = Uuid::v7();
        $this->slug = $slug;
        $this->name = $name;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function id(): Uuid
    {
        return $this->id;
    }

    public function slug(): string
    {
        return $this->slug;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function rename(string $name): void
    {
        $this->name = $name;
    }

    public function status(): string
    {
        return $this->status;
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    /**
     * A tenant becomes usable only after its first user verifies their email.
     * Until then it is `pending` and gets purged - otherwise an open signup form
     * is a tenant-spam endpoint.
     */
    public function activate(): void
    {
        $this->status = self::STATUS_ACTIVE;
        $this->activatedAt = new \DateTimeImmutable();
    }

    public function suspend(): void
    {
        $this->status = self::STATUS_SUSPENDED;
    }

    public function plan(): string
    {
        return $this->plan;
    }

    public function changePlan(string $plan): void
    {
        $this->plan = $plan;
    }

    public function defaultLocale(): string
    {
        return $this->defaultLocale;
    }

    public function setDefaultLocale(string $locale): void
    {
        $this->defaultLocale = $locale;
    }

    public function version(): int
    {
        return $this->version;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => (string) $this->id,
            'slug' => $this->slug,
            'name' => $this->name,
            'status' => $this->status,
            'plan' => $this->plan,
            'defaultLocale' => $this->defaultLocale,
            'version' => $this->version,
            'createdAt' => $this->createdAt->format(\DATE_ATOM),
        ];
    }
}
