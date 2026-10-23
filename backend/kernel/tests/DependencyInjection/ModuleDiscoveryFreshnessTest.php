<?php

declare(strict_types=1);

namespace OpenEnu\Kernel\Tests\DependencyInjection;

use OpenEnu\Kernel\DependencyInjection\OpenEnuKernelExtension;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Resource\ResourceInterface;
use Symfony\Component\Config\Resource\SelfCheckingResourceInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\Filesystem\Filesystem;

/**
 * What module discovery makes the container rebuild for.
 *
 * In debug every resource is checked on every request, so the set must react
 * to a manifest and to nothing else: a check that walks the module tree costs
 * most of a second per request through a macOS bind mount, and grows with
 * every file a module adds.
 */
#[CoversClass(OpenEnuKernelExtension::class)]
final class ModuleDiscoveryFreshnessTest extends TestCase
{
    /** The container counts as built this long ago; what the test then writes is newer. */
    private const int BUILT_AGO = 20;

    private string $project;

    protected function setUp(): void
    {
        $this->project = sys_get_temp_dir() . '/open-enu-discovery-' . bin2hex(random_bytes(4));
        $this->module('Alpha', old: true);
        $this->write('src/Module/Alpha/Entity/Thing.php', '<?php', old: true);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->project);
    }

    public function testEditingAModulesCodeDoesNotRebuildTheContainer(): void
    {
        $resources = $this->discover();
        $this->write('src/Module/Alpha/Entity/Thing.php', '<?php // edited');
        $this->write('src/Module/Alpha/Entity/Other.php', '<?php');
        $this->write('src/Module/Alpha/Service/New.php', '<?php');

        self::assertTrue($this->fresh($resources), 'Only a manifest may invalidate discovery.');
    }

    public function testEditingAManifestRebuildsTheContainer(): void
    {
        $resources = $this->discover();
        $this->write('src/Module/Alpha/module.yaml', "name: Alpha\ndescription: Edited.\n");

        self::assertFalse($this->fresh($resources));
    }

    public function testAddingAModuleRebuildsTheContainer(): void
    {
        $resources = $this->discover();
        $this->module('Beta');

        self::assertFalse($this->fresh($resources));
    }

    public function testRemovingAModuleRebuildsTheContainer(): void
    {
        $this->module('Beta', old: true);
        $resources = $this->discover();
        (new Filesystem())->remove($this->project . '/src/Module/Beta');

        self::assertFalse($this->fresh($resources));
    }

    /**
     * The resources as the container cache keeps them: serialized at build
     * time, which is when a glob takes its fingerprint.
     *
     * @return list<ResourceInterface>
     */
    private function discover(): array
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.project_dir', $this->project);
        (new \ReflectionMethod(OpenEnuKernelExtension::class, 'modules'))->invoke(new OpenEnuKernelExtension(), $container);

        /** @var list<ResourceInterface> */
        return unserialize(serialize($container->getResources()));
    }

    /** @param list<ResourceInterface> $resources */
    private function fresh(array $resources): bool
    {
        foreach ($resources as $resource) {
            // What the container cache checks; anything else it could not check.
            self::assertInstanceOf(SelfCheckingResourceInterface::class, $resource);
            if (!$resource->isFresh(time() - self::BUILT_AGO)) {
                return false;
            }
        }

        return true;
    }

    private function module(string $name, bool $old = false): void
    {
        $this->write(sprintf('src/Module/%s/module.yaml', $name), sprintf("name: %s\ndescription: A module.\n", $name), $old);
    }

    /** An `$old` file predates the build; anything else is a change made after it. */
    private function write(string $path, string $content, bool $old = false): void
    {
        $file = $this->project . '/' . $path;
        (new Filesystem())->dumpFile($file, $content);

        if ($old) {
            touch($file, time() - 2 * self::BUILT_AGO);
        }
    }
}
