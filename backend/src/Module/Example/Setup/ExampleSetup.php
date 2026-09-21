<?php

declare(strict_types=1);

namespace App\Module\Example\Setup;

use App\Module\Example\Entity\Project;
use Doctrine\ORM\EntityManagerInterface;
use OpenEnu\Kernel\Setup\TenantSetupInterface;

/**
 * What this module puts in place for a new tenant.
 *
 * Both methods are idempotent, which is not optional: provisioning is retried
 * after a failed signup or a fixed bug, and a second run that duplicates its
 * defaults turns one bad signup into permanent bad data.
 */
final readonly class ExampleSetup implements TenantSetupInterface
{
    public const string SEEDED_NAME = 'Getting started';

    public function __construct(private EntityManagerInterface $em)
    {
    }

    public function onTenantCreated(string $tenantId): void
    {
        // Nothing this module cannot function without. The distinction matters:
        // `onTenantCreated` runs for every real customer, `seedExamples` only
        // where sample data is wanted.
    }

    public function seedExamples(string $tenantId): void
    {
        $repository = $this->em->getRepository(Project::class);

        // Idempotence by identity rather than by count: re-running must not add
        // a second copy, and must not stop an operator re-seeding after a wipe.
        if ($repository->findOneBy(['name' => self::SEEDED_NAME]) !== null) {
            return;
        }

        $project = new Project(self::SEEDED_NAME, $tenantId);
        $project->setClientReference('ACME-0001');

        $this->em->persist($project);
        $this->em->flush();
    }
}
