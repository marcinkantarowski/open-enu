<?php

declare(strict_types=1);

namespace OpenEnu\Kernel\Tests\DependencyInjection;

use OpenEnu\Kernel\DependencyInjection\GuardedGlobResource;
use OpenEnu\Kernel\DependencyInjection\GuardGlobResourcesPass;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Resource\FileResource;
use Symfony\Component\Config\Resource\GlobResource;
use Symfony\Component\Config\Resource\SelfCheckingResourceInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\Filesystem\Filesystem;

/**
 * A guarded glob answers as the glob would, and lists nothing while no
 * directory under it changed.
 *
 * Only the answers can be asserted here - that the listing is skipped shows
 * in the time a request takes, not in a result. So every case below is one
 * where the guard and the glob must agree, including those where the guard
 * has to hand the question back.
 */
#[CoversClass(GuardedGlobResource::class)]
#[CoversClass(GuardGlobResourcesPass::class)]
final class GuardedGlobResourceTest extends TestCase
{
    /** The container counts as built this long ago; the tree is older still. */
    private const int BUILT_AGO = 20;

    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/open-enu-guard-' . bin2hex(random_bytes(4));
        $this->write('Service/Mailer.php');
        $this->write('Service/Sub/Queue.php');
        $this->age();
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->dir);
    }

    public function testNothingChangedIsFresh(): void
    {
        self::assertTrue($this->guarded()->isFresh($this->builtAt()));
    }

    public function testAFileAddedInASubdirectoryIsStale(): void
    {
        $resource = $this->guarded();
        $this->write('Service/Sub/Retry.php');

        self::assertFalse($resource->isFresh($this->builtAt()));
    }

    public function testADirectoryTouchedWithTheSameFilesIsStillFresh(): void
    {
        // What an editor's safe write leaves: a new mtime, the same listing.
        $resource = $this->guarded();
        touch($this->dir . '/Service/Sub');

        self::assertTrue($resource->isFresh($this->builtAt()));
    }

    public function testAFileAddedInTheSecondOfTheBuildIsSeen(): void
    {
        // A whole-second mtime cannot tell "before the build" from "just after"
        // within that second, so such a directory is listed, not trusted.
        touch($this->dir . '/Service/Sub', $this->builtAt());
        $resource = $this->guarded();
        $this->write('Service/Sub/Retry.php');
        touch($this->dir . '/Service/Sub', $this->builtAt());

        self::assertFalse($resource->isFresh($this->builtAt()));
    }

    public function testARemovedDirectoryIsStale(): void
    {
        $resource = $this->guarded();
        (new Filesystem())->remove($this->dir . '/Service/Sub');
        $this->age();

        self::assertFalse($resource->isFresh($this->builtAt()));
    }

    public function testThePassGuardsGlobsOnlyAndKeepsEveryOtherResource(): void
    {
        $container = new ContainerBuilder();
        $container->addResource(new GlobResource($this->dir . '/Service', '/{*,**/*}.php', true));
        $container->addResource(new FileResource($this->dir . '/Service/Mailer.php'));

        (new GuardGlobResourcesPass())->process($container);

        $kinds = array_map(static fn (object $r): string => $r::class, $container->getResources());
        sort($kinds);
        self::assertSame([GuardedGlobResource::class, FileResource::class], $kinds);
    }

    /** As the container cache keeps it: serialized at build time, which is when the glob takes its fingerprint. */
    private function guarded(): SelfCheckingResourceInterface
    {
        $resource = unserialize(serialize(new GuardedGlobResource(new GlobResource($this->dir . '/Service', '/{*,**/*}.php', true))));
        self::assertInstanceOf(SelfCheckingResourceInterface::class, $resource);

        return $resource;
    }

    private function builtAt(): int
    {
        return time() - self::BUILT_AGO;
    }

    private function write(string $path): void
    {
        (new Filesystem())->dumpFile($this->dir . '/' . $path, '<?php');
    }

    /** Everything that exists now predates the build. */
    private function age(): void
    {
        $old = time() - 2 * self::BUILT_AGO;
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST) as $path => $entry) {
            touch((string) $path, $old);
        }
        touch($this->dir, $old);
    }
}
