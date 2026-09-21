<?php

declare(strict_types=1);

namespace App\Module\Settings\Tests\Functional;

use App\Module\Settings\Entity\Setting;
use App\Module\Settings\Entity\SettingOverride;
use App\Tests\Support\ApiTestCase;

/**
 * The same settings, read from both sides of the platform boundary.
 *
 * A tenant sees what applies to it; an operator sees the catalogue and may
 * override anything in it. The asymmetry is the feature: a tenant that could
 * re-enable a switch an operator turned off during an incident would make the
 * switch worthless (.ai/platform/PLAN.md §8.3).
 */
final class SettingsApiTest extends ApiTestCase
{
    protected function fixtures(): array
    {
        return [SettingOverride::class, Setting::class];
    }

    public function testATenantSeesWhatActuallyAppliesToIt(): void
    {
        $this->givenATenant();
        $editable = $this->makeSetting('example.digest_enabled', true, tenantEditable: true);
        $this->makeSetting('platform.exports_enabled', true, tenantEditable: false);
        $this->override($editable, false);

        $this->givenIAmSignedIn();
        $this->get('/api/settings');

        self::assertResponseIsSuccessful();
        $items = $this->indexed($this->json()['items']);

        // The default alone is rarely the answer to the question being asked,
        // so the effective value travels with it.
        self::assertSame(true, $items['example.digest_enabled']['defaultValue']);
        self::assertSame(false, $items['example.digest_enabled']['effectiveValue']);
        self::assertTrue($items['example.digest_enabled']['overridden']);

        // Visible but not theirs to change.
        self::assertFalse($items['platform.exports_enabled']['overridden']);
        self::assertFalse($items['platform.exports_enabled']['tenantEditable']);
    }

    public function testATenantMayNotChangeAPlatformSwitch(): void
    {
        $this->givenATenant();
        $this->makeSetting('platform.exports_enabled', true, tenantEditable: false);
        $this->givenIAmSignedIn();

        $this->put('/api/settings/platform.exports_enabled', ['value' => true]);

        self::assertResponseStatusCodeSame(400);
    }

    public function testAnOperatorSeesTheCatalogueAcrossEveryTenant(): void
    {
        $this->givenATenant();
        $this->makeSetting('platform.exports_enabled', true, tenantEditable: false);
        $this->givenIAmAnOperator();

        $this->get('/api/manager/settings');

        self::assertResponseIsSuccessful();
        $items = $this->indexed($this->json()['items']);
        self::assertArrayHasKey('platform.exports_enabled', $items);
        // No `effectiveValue` here: there is no tenant to be effective FOR.
        self::assertArrayNotHasKey('effectiveValue', $items['platform.exports_enabled']);
    }

    public function testAnOperatorMayOverrideASwitchTheTenantMayNotTouch(): void
    {
        $tenantId = $this->givenATenant();
        $this->makeSetting('platform.exports_enabled', true, tenantEditable: false);
        $this->givenIAmAnOperator();

        $this->put('/api/manager/tenants/' . $tenantId . '/settings/platform.exports_enabled', ['value' => false]);

        // The kill switch in its operational form: one customer, no deploy.
        self::assertResponseIsSuccessful();

        $this->get('/api/manager/settings');
        self::assertResponseIsSuccessful();
    }

    public function testTheTenantCatalogueIsClosedToAnAnonymousCaller(): void
    {
        $this->givenATenant();

        $this->get('/api/settings');

        self::assertResponseStatusCodeSame(401);
    }

    /**
     * @param list<array<string, mixed>> $items
     *
     * @return array<string, array<string, mixed>>
     */
    private function indexed(array $items): array
    {
        $byIdentifier = [];

        foreach ($items as $item) {
            $byIdentifier[(string) $item['identifier']] = $item;
        }

        return $byIdentifier;
    }

    private function makeSetting(string $identifier, mixed $default, bool $tenantEditable): Setting
    {
        $setting = new Setting($identifier, ucfirst($identifier), Setting::TYPE_BOOL, $default);
        $setting->setTenantEditable($tenantEditable);

        $this->em->persist($setting);
        $this->em->flush();

        return $setting;
    }

    private function override(Setting $setting, mixed $value): void
    {
        $this->em->persist(new SettingOverride($this->tenantId, $setting, $value));
        $this->em->flush();
    }
}
