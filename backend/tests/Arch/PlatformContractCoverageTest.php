<?php

declare(strict_types=1);

namespace App\Tests\Arch;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use OpenEnu\Kernel\Contract\VersionedInterface;
use OpenEnu\Kernel\Gdpr\GdprSubjectInterface;
use OpenEnu\Kernel\Module\ModuleRegistry;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Routing\RouterInterface;

/**
 * Coverage checks that need the real container.
 *
 * The PHPStan rules police how code is written; these police what is *missing*,
 * which static analysis of a single file cannot see. Both kinds are needed: a
 * module can be perfectly well-formed and still have no optimistic locking on
 * the endpoint that needs it.
 */
final class PlatformContractCoverageTest extends KernelTestCase
{
    private function entityManager(): EntityManagerInterface
    {
        // Boot once. KernelTestCase::bootKernel() SHUTS DOWN any running kernel
        // and starts a new one, so calling it again silently invalidates every
        // container reference the test already holds - which fails later, with
        // an error that points at the container rather than at the reboot.
        if (self::$kernel === null) {
            self::bootKernel();
        }

        $em = self::getContainer()->get('doctrine.orm.entity_manager');
        \assert($em instanceof EntityManagerInterface);

        return $em;
    }

    /** @return list<ClassMetadata<object>> */
    private function entities(): array
    {
        return $this->entityManager()->getMetadataFactory()->getAllMetadata();
    }

    public function testEveryEntityWithAVersionColumnImplementsVersionedInterface(): void
    {
        // The column alone does nothing: OptimisticLock takes a
        // VersionedInterface, so an entity with `#[ORM\Version]` and no
        // interface has concurrency *tracking* and no concurrency *protection* -
        // which looks identical in a schema diff.
        $missing = [];

        foreach ($this->entities() as $meta) {
            if (!$meta->isVersioned) {
                continue;
            }
            if (!$meta->getReflectionClass()->implementsInterface(VersionedInterface::class)) {
                $missing[] = $meta->getName();
            }
        }

        self::assertSame([], $missing, sprintf(
            "These entities have an #[ORM\\Version] column but do not implement %s, "
            . "so OptimisticLock cannot be used with them:\n  %s",
            VersionedInterface::class,
            implode("\n  ", $missing),
        ));
    }

    public function testEveryVersionedEntityActuallyHasTheColumn(): void
    {
        // The mirror: implementing the interface without the column means
        // version() returns a value Doctrine never increments, so every
        // concurrency check silently passes.
        $missing = [];

        foreach ($this->entities() as $meta) {
            if (!$meta->getReflectionClass()->implementsInterface(VersionedInterface::class)) {
                continue;
            }
            if (!$meta->isVersioned) {
                $missing[] = $meta->getName();
            }
        }

        self::assertSame([], $missing, sprintf(
            "These entities implement %s but have no #[ORM\\Version] column, so their "
            . "version never changes and every conflict check passes:\n  %s",
            VersionedInterface::class,
            implode("\n  ", $missing),
        ));
    }

    public function testAModuleWithWriteEndpointsHasVersionedEntities(): void
    {
        // Heuristic but load-bearing: it catches "added an update endpoint,
        // forgot optimistic locking existed", which is the common omission.
        $entities = $this->entities();
        $router = self::getContainer()->get('router');
        \assert($router instanceof RouterInterface);

        $modulesWithWrites = [];
        foreach ($router->getRouteCollection() as $route) {
            $controller = $route->getDefault('_controller');
            if (!\is_string($controller)) {
                continue;
            }
            if (array_intersect(['PUT', 'PATCH'], $route->getMethods()) === []) {
                continue;
            }
            if (preg_match('/^App\\\\Module\\\\([A-Za-z0-9_]+)\\\\/', $controller, $m) === 1) {
                $modulesWithWrites[$m[1]] = true;
            }
        }

        $versionedModules = [];
        foreach ($entities as $meta) {
            if ($meta->getReflectionClass()->implementsInterface(VersionedInterface::class)
                && preg_match('/^App\\\\Module\\\\([A-Za-z0-9_]+)\\\\/', $meta->getName(), $m) === 1) {
                $versionedModules[$m[1]] = true;
            }
        }

        $unprotected = array_diff(array_keys($modulesWithWrites), array_keys($versionedModules));

        self::assertSame([], array_values($unprotected), sprintf(
            "These modules expose PUT/PATCH routes but have no entity implementing %s.\n"
            . "Concurrent edits there are last-write-wins: the first person's change disappears "
            . "with no error.\n  %s",
            VersionedInterface::class,
            implode("\n  ", $unprotected),
        ));
    }

    public function testEveryModuleHoldingUserDataCanExportAndEraseIt(): void
    {
        // "Audit plus encryption equals GDPR" is a claim until something checks
        // that every module holding personal data can actually answer a
        // subject-access or erasure request.
        $entities = $this->entities();
        $container = self::getContainer();

        $modulesWithUserData = [];
        foreach ($entities as $meta) {
            if (preg_match('/^App\\\\Module\\\\([A-Za-z0-9_]+)\\\\/', $meta->getName(), $m) !== 1) {
                continue;
            }
            foreach ($meta->getFieldNames() as $field) {
                // Only columns that unambiguously name a PERSON. `owner_id` and
                // `subject_id` are polymorphic record references - an attachment
                // belongs to an invoice, an audit entry is about a project - and
                // treating those as personal data makes the check cry wolf,
                // which is how a check stops being believed.
                if (\in_array(strtolower($meta->getColumnName($field)), ['user_id', 'actor_id'], true)) {
                    $modulesWithUserData[$m[1]] = $meta->getName();
                }
            }
            foreach ($meta->getAssociationNames() as $association) {
                if (str_contains(strtolower($association), 'user')) {
                    $modulesWithUserData[$m[1]] = $meta->getName();
                }
            }
        }

        if ($modulesWithUserData === []) {
            self::markTestSkipped('No module holds user-linked data yet (tenancy arrives in Phase 3).');
        }

        // Found by reflection rather than by tag: the test container does not
        // expose findTaggedServiceIds(), and the question being asked is about
        // the CODE - does this module implement the contract - not about how
        // the container happened to wire it.
        $covered = [];
        $registry = $container->get(ModuleRegistry::class);
        \assert($registry instanceof ModuleRegistry);

        foreach ($registry->all() as $module => $info) {
            foreach ((glob($info['path'] . '/Service/*.php') ?: []) as $file) {
                $class = 'App\\Module\\' . $module . '\\Service\\' . basename($file, '.php');

                if (class_exists($class) && is_subclass_of($class, GdprSubjectInterface::class)) {
                    $covered[$module] = true;
                }
            }
        }

        $uncovered = array_diff_key($modulesWithUserData, $covered);

        self::assertSame([], $uncovered, sprintf(
            "These modules hold user-linked data but implement no %s, so an export or "
            . "erasure request would silently miss them:\n  %s",
            GdprSubjectInterface::class,
            implode("\n  ", array_map(
                static fn (string $module, string $entity): string => "$module ($entity)",
                array_keys($uncovered),
                $uncovered,
            )),
        ));
    }
}
