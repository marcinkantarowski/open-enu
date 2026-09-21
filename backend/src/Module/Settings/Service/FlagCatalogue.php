<?php

declare(strict_types=1);

namespace App\Module\Settings\Service;

use App\Module\Settings\Entity\Setting;
use App\Module\Settings\Repository\SettingRepository;
use Doctrine\ORM\EntityManagerInterface;
use OpenEnu\Kernel\Attribute\InfrastructureWrite;
use OpenEnu\Kernel\Flags\FlagDefinition;
use OpenEnu\Kernel\Flags\FlagProviderInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Reconciles what the modules declare with what is stored.
 *
 * Declarations live beside the code they guard; storage is what an operator
 * edits. This is the one place the two meet, and the rules are deliberately
 * asymmetric:
 *
 *  • A new declaration creates a row.
 *  • A changed name, description, category or `tenantEditable` updates the row -
 *    those are the module's to define.
 *  • A changed **default does not overwrite a stored one**. The default is what
 *    applies before anyone decides; once a value is in the database an operator
 *    may have deliberately put it there, and a deploy silently reverting that is
 *    how a kill switch turns itself back on.
 *  • A declaration that disappears leaves its row alone. The row may carry
 *    tenant overrides, and orphaning is visible in the console while deleting is
 *    not.
 */
final readonly class FlagCatalogue
{
    /** @param iterable<FlagProviderInterface> $providers */
    public function __construct(
        #[AutowireIterator('open_enu.flag_provider')] private iterable $providers,
        private SettingRepository $settings,
        private EntityManagerInterface $em,
    ) {
    }

    /** @return list<FlagDefinition> */
    public function declared(): array
    {
        $declared = [];

        foreach ($this->providers as $provider) {
            foreach ($provider->flags() as $flag) {
                if (isset($declared[$flag->identifier])) {
                    // Global, like permissions: a flag with two owners has no
                    // meaning, and whichever module loaded last would win.
                    throw new \LogicException(sprintf('Flag "%s" is declared twice.', $flag->identifier));
                }

                $declared[$flag->identifier] = $flag;
            }
        }

        return array_values($declared);
    }

    /**
     * @return array{created: list<string>, updated: list<string>, unchanged: list<string>}
     */
    #[InfrastructureWrite(reason: 'reconciling declarations with storage; the audit trail records the value changes an operator makes, not the catalogue')]
    public function sync(): array
    {
        $report = ['created' => [], 'updated' => [], 'unchanged' => []];

        foreach ($this->declared() as $flag) {
            $setting = $this->settings->byIdentifier($flag->identifier);

            if ($setting === null) {
                $setting = new Setting($flag->identifier, $flag->name, $flag->type, $flag->default);
                $setting->setDescription($flag->description);
                $setting->setCategory($flag->category);
                $setting->setTenantEditable($flag->tenantEditable);

                $this->em->persist($setting);
                $report['created'][] = $flag->identifier;

                continue;
            }

            $before = $setting->toArray();

            $setting->setDescription($flag->description);
            $setting->setCategory($flag->category);
            $setting->setTenantEditable($flag->tenantEditable);

            // Doctrine writes nothing when nothing changed, so comparing the
            // serialised form is how "updated" stays truthful rather than
            // reporting every identifier on every run.
            $report[$setting->toArray() === $before ? 'unchanged' : 'updated'][] = $flag->identifier;
        }

        $this->em->flush();

        return $report;
    }
}
