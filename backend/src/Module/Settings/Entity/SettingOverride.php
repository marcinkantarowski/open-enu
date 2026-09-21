<?php

declare(strict_types=1);

namespace App\Module\Settings\Entity;

use App\Module\Settings\Repository\SettingOverrideRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use OpenEnu\Kernel\Contract\TenantScopedInterface;
use Symfony\Component\Uid\Uuid;

/**
 * One tenant's answer for one setting.
 *
 * Absence means "inherit the global default" - so turning a flag off for
 * everyone and on for one customer is one row, and removing that row restores
 * the default rather than pinning yesterday's value.
 */
#[ORM\Entity(repositoryClass: SettingOverrideRepository::class)]
#[ORM\Table(name: 'setting_override')]
#[ORM\UniqueConstraint(name: 'uniq_override_tenant_setting', columns: ['tenant_id', 'setting_id'])]
class SettingOverride implements TenantScopedInterface
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\Column(name: 'tenant_id', type: 'uuid')]
    private Uuid $tenantId;

    #[ORM\ManyToOne(targetEntity: Setting::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Setting $setting;

    /** @var array{value: mixed} boxed, for the same reason as Setting::$defaultValue */
    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true])]
    private array $value;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $tenantId, Setting $setting, mixed $value)
    {
        $this->id = Uuid::v7();
        $this->tenantId = Uuid::fromString($tenantId);
        $this->setting = $setting;
        $this->value = ['value' => $value];
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function id(): Uuid
    {
        return $this->id;
    }

    public function tenantId(): string
    {
        return (string) $this->tenantId;
    }

    public function setting(): Setting
    {
        return $this->setting;
    }

    public function value(): mixed
    {
        return $this->value['value'] ?? null;
    }

    public function setValue(mixed $value): void
    {
        $this->value = ['value' => $value];
        $this->updatedAt = new \DateTimeImmutable();
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => (string) $this->id,
            'identifier' => $this->setting->identifier(),
            'value' => $this->value(),
            'updatedAt' => $this->updatedAt->format(\DATE_ATOM),
        ];
    }
}
