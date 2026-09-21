<?php

declare(strict_types=1);

namespace App\Module\Example\Fixtures;

use App\Module\Example\Entity\Project;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Known rows, at known ids.
 *
 * Plain PHP rather than a fixtures bundle: this is a factory a test calls, and
 * the ids are constants so a test can assert on a URL instead of threading a
 * generated value through every step (.ai/platform/PLAN.md §12.1, determinism).
 *
 * Not registered as a service and never reachable from a request - it lives
 * under `Fixtures/`, which the guardrails exempt for exactly this reason.
 */
final readonly class ProjectFixtures
{
    public const string ALPHA = '0192f000-0000-7000-8000-00000000a001';
    public const string BETA = '0192f000-0000-7000-8000-00000000b002';

    /** Two rows, so a count assertion can tell "the list works" from "the list is the fixture". */

    public function __construct(private EntityManagerInterface $em)
    {
    }

    public function load(string $tenantId): void
    {
        $alpha = new Project('Alpha', $tenantId, self::ALPHA);
        $alpha->setClientReference('ACME-0001');
        $alpha->setAttributes(['priority' => 'high']);

        $beta = new Project('Beta', $tenantId, self::BETA);

        $this->em->persist($alpha);
        $this->em->persist($beta);
        $this->em->flush();
    }
}
