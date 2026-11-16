<?php

declare(strict_types=1);

namespace App\Module\Role\Repository;

use App\Module\Role\Contract\PermissionsInterface;
use App\Module\Role\Entity\Role;
use App\Module\Role\Service\RoleGrantSource;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use OpenEnu\Kernel\Attribute\Unscoped;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\Uid\Uuid;

/** @extends ServiceEntityRepository<Role> */
#[AsAlias(RoleGrantSource::class)]
final class RoleRepository extends ServiceEntityRepository implements RoleGrantSource
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Role::class);
    }

    /**
     * The current tenant's stored roles, through the filter.
     *
     * @return list<Role>
     */
    public function inTenant(): array
    {
        /** @var list<Role> */
        return $this->createQueryBuilder('r')->orderBy('r.key')->getQuery()->getResult();
    }

    public function get(string $id): ?Role
    {
        return Uuid::isValid($id) ? $this->find(Uuid::fromString($id)) : null;
    }

    public function byKey(string $key): ?Role
    {
        return $this->findOneBy(['key' => $key]);
    }

    /**
     * The stored role, or - for `admin` and `member`, which every tenant has
     * whether or not it saved them - a new, unsaved one with the built-in rule.
     * Null for `owner` (never stored, never edited) and for an unknown key.
     */
    public function editable(string $key, string $tenantId): ?Role
    {
        return $this->byKey($key) ?? (\in_array($key, [PermissionsInterface::ADMIN, PermissionsInterface::MEMBER], true)
            ? Role::unsaved($tenantId, $key)
            : null);
    }

    /**
     * One tenant's grants by role key, without that tenant in scope.
     *
     * The session payload is built at login and at a tenant switch, before the
     * scope belongs to the tenant being entered; through the filter every role
     * would read as absent, and absent falls back to the built-in rule - a
     * tenant's restriction silently ignored in exactly the response that tells
     * the UI what to show.
     *
     * @return array<string, array{permissions: list<string>, known: list<string>, template: ?string}>
     */
    #[Unscoped(reason: 'permissions are resolved for a named tenant, at login before the scope is that tenant')]
    public function grantsFor(string $tenantId): array
    {
        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            'SELECT role_key, permissions, known, template FROM role_role WHERE tenant_id = ?',
            [$tenantId],
        );

        $grants = [];
        foreach ($rows as $row) {
            $grants[(string) $row['role_key']] = [
                'permissions' => self::list($row['permissions']),
                'known' => self::list($row['known']),
                'template' => \is_string($row['template']) ? $row['template'] : null,
            ];
        }

        return $grants;
    }

    /** A deleted tenant's roles, whatever the scope the cleanup runs in. */
    #[Unscoped(reason: 'the tenant is gone; its cleanup names it rather than being inside it')]
    public function deleteForTenant(string $tenantId): int
    {
        return (int) $this->getEntityManager()->getConnection()->executeStatement(
            'DELETE FROM role_role WHERE tenant_id = ?',
            [$tenantId],
        );
    }

    /** @return list<string> */
    private static function list(mixed $json): array
    {
        $decoded = \is_string($json) ? json_decode($json, true) : null;

        return \is_array($decoded) ? array_values(array_filter($decoded, 'is_string')) : [];
    }
}
