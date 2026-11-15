<?php

declare(strict_types=1);

namespace App\Module\Role\Entity;

use App\Module\Role\Repository\RoleRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use OpenEnu\Kernel\Contract\TenantScopedInterface;
use OpenEnu\Kernel\Contract\VersionedInterface;
use Symfony\Component\Uid\Uuid;

/**
 * A bundle of permissions a tenant defines for itself (ADR-0025).
 *
 * A membership names its role by `key`, never by id, so the three built-in keys
 * - `owner`, `admin`, `member` - mean the same thing in every tenant and in the
 * code. `owner` is never stored: it has every permission and cannot change.
 * `admin` and `member` are stored once a tenant has them, and may be edited.
 *
 * A role records which permissions EXISTED when it was last saved (`known`),
 * besides which it grants. A permission declared later - a module added in an
 * update - is then decided by the role's `template`: the built-in rule for
 * `admin` and `member`, refused for a tenant's own role. Without it every
 * tenant's admin would silently lose each new module, and the only fix would be
 * an owner in every tenant ticking a box.
 */
#[ORM\Entity(repositoryClass: RoleRepository::class)]
#[ORM\Table(name: 'role_role')]
#[ORM\UniqueConstraint(name: 'uniq_role_tenant_key', columns: ['tenant_id', 'role_key'])]
#[ORM\Index(columns: ['tenant_id'], name: 'idx_role_tenant')]
class Role implements TenantScopedInterface, VersionedInterface
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\Column(name: 'tenant_id', type: 'uuid')]
    private Uuid $tenantId;

    #[ORM\Column(name: 'role_key', type: Types::STRING, length: 40)]
    private string $key;

    /** Null on a built-in role the tenant has not renamed: the UI shows it in the reader's language. */
    #[ORM\Column(type: Types::STRING, length: 80, nullable: true)]
    private ?string $name;

    /** @var list<string> */
    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true])]
    private array $permissions = [];

    /** @var list<string> every permission declared when this role was last saved */
    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true])]
    private array $known = [];

    /** `admin`, `member`, or null for a tenant's own role. */
    #[ORM\Column(type: Types::STRING, length: 20, nullable: true)]
    private ?string $template;

    /** Two people editing one role: the second gets a 409, not a silent overwrite. */
    #[ORM\Version]
    #[ORM\Column(type: Types::INTEGER)]
    private int $version = 1;

    public function __construct(string $tenantId, string $key, ?string $name, ?string $template = null)
    {
        $this->id = Uuid::v7();
        $this->tenantId = Uuid::fromString($tenantId);
        $this->key = $key;
        $this->name = $name;
        $this->template = $template;
    }

    /**
     * A built-in role this tenant has not saved yet: version 0, so the first
     * save is checked like any other - a second edit made from the same read
     * is a conflict, not an overwrite. Doctrine stores 1 when it inserts.
     */
    public static function unsaved(string $tenantId, string $key): self
    {
        $role = new self($tenantId, $key, null, $key);
        $role->version = 0;

        return $role;
    }

    public function id(): string
    {
        return (string) $this->id;
    }

    public function tenantId(): string
    {
        return (string) $this->tenantId;
    }

    public function version(): int
    {
        return $this->version;
    }

    public function key(): string
    {
        return $this->key;
    }

    public function isBuiltIn(): bool
    {
        return $this->template !== null;
    }

    /**
     * @param list<string> $permissions what this role grants
     * @param list<string> $known       every permission that exists right now
     */
    public function define(?string $name, array $permissions, array $known): void
    {
        $this->name = $name;
        $this->permissions = self::sorted($permissions);
        $this->known = self::sorted($known);
    }

    /** @return array{permissions: list<string>, known: list<string>, template: ?string} */
    public function grants(): array
    {
        return ['permissions' => $this->permissions, 'known' => $this->known, 'template' => $this->template];
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => (string) $this->id,
            'key' => $this->key,
            'name' => $this->name,
            'builtIn' => $this->isBuiltIn(),
            'permissions' => $this->permissions,
            'version' => $this->version,
        ];
    }

    /**
     * @param list<string> $values
     *
     * @return list<string>
     */
    private static function sorted(array $values): array
    {
        $values = array_values(array_unique($values));
        sort($values);

        return $values;
    }
}
