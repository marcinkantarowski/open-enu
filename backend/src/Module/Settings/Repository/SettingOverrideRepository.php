<?php

declare(strict_types=1);

namespace App\Module\Settings\Repository;

use App\Module\Settings\Entity\Setting;
use App\Module\Settings\Entity\SettingOverride;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ManagerRegistry;
use OpenEnu\Kernel\Attribute\Unscoped;

/** @extends ServiceEntityRepository<SettingOverride> */
final class SettingOverrideRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry, private readonly Connection $db)
    {
        parent::__construct($registry, SettingOverride::class);
    }

    public function forSetting(Setting $setting): ?SettingOverride
    {
        return $this->findOneBy(['setting' => $setting]);
    }

    /** @return list<SettingOverride> */
    public function forCurrentTenant(): array
    {
        return $this->createQueryBuilder('o')->getQuery()->getResult();
    }

    /**
     * Resolve one tenant's value without needing that tenant in scope.
     *
     * Flags are read during request setup - before, and sometimes instead of,
     * a tenant scope being established - and by the operator console on behalf
     * of a tenant it is not inside. Going through the ORM here would hit the
     * fail-closed filter and silently report every override as absent, which
     * reads as "the default applies" rather than as an error.
     *
     * @return array{value: mixed}|null null means "no override; inherit"
     */
    #[Unscoped(reason: 'flag resolution happens before a scope exists, and on behalf of other tenants')]
    public function rawValueFor(string $tenantId, string $identifier): ?array
    {
        $row = $this->db->fetchOne(
            'SELECT o.value
             FROM setting_override o
             JOIN setting s ON s.id = o.setting_id
             WHERE o.tenant_id = ? AND s.identifier = ?',
            [$tenantId, $identifier],
        );

        if (!\is_string($row)) {
            return null;
        }

        /** @var array{value: mixed}|null $decoded */
        $decoded = json_decode($row, true);

        return \is_array($decoded) ? $decoded : null;
    }
}
