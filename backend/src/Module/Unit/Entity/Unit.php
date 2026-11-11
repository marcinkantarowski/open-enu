<?php

declare(strict_types=1);

namespace App\Module\Unit\Entity;

use App\Module\Unit\Repository\UnitRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use OpenEnu\Kernel\Contract\TenantScopedInterface;
use OpenEnu\Kernel\Contract\VersionedInterface;
use Symfony\Component\Uid\Uuid;

/**
 * One node of a tenant's tree (ADR-0025).
 *
 * `path` is the materialised path - `/root/child/this/`, ids including its own -
 * so "this unit and everything under it" is one `LIKE 'path%'`, the only
 * question membership scoping asks. A move rewrites the paths of one subtree.
 *
 * Tenant-scoped, NOT unit-scoped: the tree itself is visible to the whole
 * tenant, so someone narrowed to one site can still see where it sits.
 *
 * Never deleted, only archived: rows in other modules carry a unit id with no
 * foreign key (ADR-0002), and a deleted unit would orphan them silently.
 */
#[ORM\Entity(repositoryClass: UnitRepository::class)]
#[ORM\Table(name: 'unit_unit')]
#[ORM\Index(columns: ['tenant_id'], name: 'idx_unit_tenant')]
class Unit implements TenantScopedInterface, VersionedInterface
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\Column(name: 'tenant_id', type: 'uuid')]
    private Uuid $tenantId;

    #[ORM\Column(type: 'uuid', nullable: true)]
    private ?Uuid $parentId = null;

    #[ORM\Column(type: Types::STRING, length: 200)]
    private string $name;

    /** Free text the tenant chooses - "branch", "site". The platform reads nothing into it. */
    #[ORM\Column(type: Types::STRING, length: 40)]
    private string $kind;

    #[ORM\Column(type: Types::TEXT)]
    private string $path;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $archivedAt = null;

    /** Two people renaming one unit: the second gets a 409, not a silent overwrite. */
    #[ORM\Version]
    #[ORM\Column(type: Types::INTEGER)]
    private int $version = 1;

    public function __construct(string $tenantId, string $name, string $kind, ?self $parent = null)
    {
        $this->id = Uuid::v7();
        $this->tenantId = Uuid::fromString($tenantId);
        $this->name = $name;
        $this->kind = $kind;
        $this->placeUnder($parent);
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

    public function path(): string
    {
        return $this->path;
    }

    public function isArchived(): bool
    {
        return $this->archivedAt !== null;
    }

    /** Whether $other is this unit or anywhere under it. */
    public function contains(self $other): bool
    {
        return str_starts_with($other->path, $this->path);
    }

    public function rename(string $name, string $kind): void
    {
        $this->name = $name;
        $this->kind = $kind;
    }

    public function archive(): void
    {
        $this->archivedAt ??= new \DateTimeImmutable();
    }

    /** Re-root under $parent (null: a top-level unit). Descendants are re-pathed by the caller. */
    public function placeUnder(?self $parent): void
    {
        $this->parentId = $parent?->id;
        $this->path = ($parent !== null ? $parent->path : '/') . $this->id . '/';
    }

    /** Re-path a descendant after one of its ancestors moved. */
    public function rebase(string $oldPrefix, string $newPrefix): void
    {
        $this->path = $newPrefix . substr($this->path, \strlen($oldPrefix));
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id(),
            'parentId' => $this->parentId !== null ? (string) $this->parentId : null,
            'name' => $this->name,
            'kind' => $this->kind,
            'depth' => substr_count($this->path, '/') - 2,
            'archived' => $this->isArchived(),
            'version' => $this->version,
        ];
    }
}
