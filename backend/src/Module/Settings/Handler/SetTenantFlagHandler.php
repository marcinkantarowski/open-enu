<?php

declare(strict_types=1);

namespace App\Module\Settings\Handler;

use App\Module\Settings\Command\SetTenantFlag;
use App\Module\Settings\Entity\SettingOverride;
use App\Module\Settings\Repository\SettingOverrideRepository;
use App\Module\Settings\Repository\SettingRepository;
use Doctrine\ORM\EntityManagerInterface;
use OpenEnu\Kernel\Cache\TenantCache;
use OpenEnu\Kernel\Command\SnapshotCollector;
use OpenEnu\Kernel\Doctrine\ScopeContext;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class SetTenantFlagHandler
{
    public function __construct(
        private SettingRepository $settings,
        private SettingOverrideRepository $overrides,
        private EntityManagerInterface $em,
        private TenantCache $cache,
        private ScopeContext $scope,
        private SnapshotCollector $snapshots,
    ) {
    }

    /** @return array<string, mixed> */
    public function __invoke(SetTenantFlag $command): array
    {
        $setting = $this->settings->byIdentifier($command->identifier)
            ?? throw new NotFoundHttpException(sprintf('No setting "%s".', $command->identifier));

        // The operator console sets flags for a tenant it is not inside, so the
        // write happens in that tenant's scope rather than the caller's - which
        // is also what makes the override row carry the right tenant_id.
        $previous = $this->scope->all();
        $this->scope->enter([ScopeContext::TENANT => $command->tenantId]);

        try {
            $override = $this->overrides->forSetting($setting);

            $this->snapshots->before([
                'identifier' => $command->identifier,
                'value' => $override?->value() ?? $setting->defaultValue(),
                'source' => $override !== null ? 'override' : 'default',
            ]);

            if ($override === null) {
                $override = new SettingOverride($command->tenantId, $setting, $command->value);
                $this->em->persist($override);
            } else {
                $override->setValue($command->value);
            }

            $this->em->flush();
            $result = $override->toArray();
        } finally {
            $this->scope->enter($previous);
        }

        // Both tags, because both caches are now wrong: the tenant's own
        // resolution, and anything else keyed on this identifier. Without this
        // the switch takes up to a minute to bite, which is a minute too long
        // when it is being used to stop an incident.
        $this->cache->invalidateTenant($command->tenantId);
        $this->cache->invalidateModule('settings');

        $this->snapshots->after($result);

        return $result;
    }
}
