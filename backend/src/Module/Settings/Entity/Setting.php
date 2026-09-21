<?php

declare(strict_types=1);

namespace App\Module\Settings\Entity;

use App\Module\Settings\Repository\SettingRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use OpenEnu\Kernel\Contract\VersionedInterface;
use Symfony\Component\Uid\Uuid;

/**
 * A flag or setting, and its global default.
 *
 * Deliberately NOT tenant-scoped: a definition belongs to the platform, and
 * tenants attach overrides to it. Scoping the definition would mean every tenant
 * needing its own copy of every flag, which is the opposite of a kill switch.
 */
#[ORM\Entity(repositoryClass: SettingRepository::class)]
#[ORM\Table(name: 'setting')]
#[ORM\UniqueConstraint(name: 'uniq_setting_identifier', columns: ['identifier'])]
class Setting implements VersionedInterface
{
    public const string TYPE_BOOL = 'bool';
    public const string TYPE_STRING = 'string';
    public const string TYPE_INT = 'int';
    public const string TYPE_JSON = 'json';

    public const array TYPES = [self::TYPE_BOOL, self::TYPE_STRING, self::TYPE_INT, self::TYPE_JSON];

    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    /** The key code checks, e.g. `billing.new_checkout`. */
    #[ORM\Column(type: Types::STRING, length: 120)]
    private string $identifier;

    #[ORM\Column(type: Types::STRING, length: 200)]
    private string $name;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(type: Types::STRING, length: 16)]
    private string $type;

    /**
     * Boxed under a `value` key rather than stored bare.
     *
     * A JSON column holding `false` and a JSON column holding SQL NULL are
     * indistinguishable through several layers of driver; boxing removes the
     * ambiguity, and a flag that reads as "unset" when it was deliberately
     * turned off is the exact failure a kill switch cannot have.
     *
     * @var array{value: mixed}
     */
    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true])]
    private array $defaultValue;

    /** Lets a tenant owner change it, rather than only an operator. */
    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $tenantEditable = false;

    #[ORM\Column(type: Types::STRING, length: 64, nullable: true)]
    private ?string $category = null;

    #[ORM\Version]
    #[ORM\Column(type: Types::INTEGER)]
    private int $version = 1;

    public function __construct(string $identifier, string $name, string $type, mixed $defaultValue)
    {
        $this->id = Uuid::v7();
        $this->identifier = $identifier;
        $this->name = $name;
        $this->type = \in_array($type, self::TYPES, true)
            ? $type
            : throw new \InvalidArgumentException(sprintf('Unknown setting type "%s".', $type));
        $this->defaultValue = ['value' => $defaultValue];
    }

    public function id(): Uuid
    {
        return $this->id;
    }

    public function identifier(): string
    {
        return $this->identifier;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function type(): string
    {
        return $this->type;
    }

    public function defaultValue(): mixed
    {
        return $this->defaultValue['value'] ?? null;
    }

    public function setDefaultValue(mixed $value): void
    {
        $this->defaultValue = ['value' => $value];
    }

    public function isTenantEditable(): bool
    {
        return $this->tenantEditable;
    }

    public function setTenantEditable(bool $editable): void
    {
        $this->tenantEditable = $editable;
    }

    public function setDescription(?string $description): void
    {
        $this->description = $description;
    }

    public function setCategory(?string $category): void
    {
        $this->category = $category;
    }

    public function version(): int
    {
        return $this->version;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => (string) $this->id,
            'identifier' => $this->identifier,
            'name' => $this->name,
            'description' => $this->description,
            'type' => $this->type,
            'defaultValue' => $this->defaultValue(),
            'tenantEditable' => $this->tenantEditable,
            'category' => $this->category,
            'version' => $this->version,
        ];
    }
}
