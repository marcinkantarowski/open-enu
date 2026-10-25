<?php

declare(strict_types=1);

namespace OpenEnu\Kernel\Tests\DependencyInjection;

use OpenEnu\Kernel\DependencyInjection\ThrottledGlobResources;
use OpenEnu\Kernel\DependencyInjection\ThrottleGlobResourcesPass;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Resource\FileResource;
use Symfony\Component\Config\Resource\GlobResource;
use Symfony\Component\Config\Resource\SelfCheckingResourceInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\Filesystem\Filesystem;

/**
 * The globs answer as they would, at most SECONDS late.
 *
 * Every case checks the answer, not the time saved: that the listing is
 * skipped shows in how long a request takes. What matters here is that a new
 * file is always noticed once the throttle has passed.
 */
#[CoversClass(ThrottledGlobResources::class)]
#[CoversClass(ThrottleGlobResourcesPass::class)]
final class ThrottledGlobResourcesTest extends TestCase
{
    /** The container counts as built this long ago; the tree is older still. */
    private const int BUILT_AGO = 20;

    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/open-enu-globs-' . bin2hex(random_bytes(4));
        $this->write('Service/Mailer.php');
        $this->write('Handler/Sub/Queue.php');
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->dir);
    }

    public function testNothingChangedIsFresh(): void
    {
        self::assertTrue($this->throttled()->isFresh($this->builtAt()));
    }

    public function testAFileAddedSinceTheLastCheckIsStale(): void
    {
        $resource = $this->throttled();
        $this->write('Handler/Sub/Retry.php');

        self::assertFalse($resource->isFresh($this->builtAt()));
    }

    public function testAFileAddedJustAfterACheckIsNoticedOnceTheThrottlePasses(): void
    {
        $resource = $this->throttled();
        self::assertTrue($resource->isFresh($this->builtAt()));

        $this->write('Service/Transport.php');
        self::assertTrue($resource->isFresh($this->builtAt()), 'inside the throttle, the last answer stands');

        touch($this->stamp(), time() - ThrottledGlobResources::SECONDS - 1);
        self::assertFalse($resource->isFresh($this->builtAt()));
    }

    public function testWhileAnotherRequestChecksTheLastAnswerStands(): void
    {
        $resource = $this->throttled();
        $this->write('Service/Transport.php');

        $other = fopen($this->stamp() . '.lock', 'c');
        self::assertNotFalse($other);
        flock($other, \LOCK_EX);
        try {
            self::assertTrue($resource->isFresh($this->builtAt()));
        } finally {
            fclose($other);
        }

        self::assertFalse($resource->isFresh($this->builtAt()), 'and once it is done, the next one checks');
    }

    public function testARemovedFileIsStale(): void
    {
        $resource = $this->throttled();
        unlink($this->dir . '/Handler/Sub/Queue.php');

        self::assertFalse($resource->isFresh($this->builtAt()));
    }

    public function testThePassThrottlesTheGlobsTogetherAndKeepsEveryOtherResource(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.cache_dir', $this->dir);
        $container->addResource(new GlobResource($this->dir . '/Service', '/{*,**/*}.php', true));
        $container->addResource(new GlobResource($this->dir . '/Handler', '/{*,**/*}.php', true));
        $container->addResource(new FileResource($this->dir . '/Service/Mailer.php'));

        (new ThrottleGlobResourcesPass())->process($container);

        $kinds = array_map(static fn (object $r): string => $r::class, $container->getResources());
        sort($kinds);
        self::assertSame([ThrottledGlobResources::class, FileResource::class], $kinds);
    }

    /** As the container cache keeps it: serialized at build time, when each glob takes its fingerprint. */
    private function throttled(): SelfCheckingResourceInterface
    {
        $resource = unserialize(serialize(new ThrottledGlobResources([
            new GlobResource($this->dir . '/Service', '/{*,**/*}.php', true),
            new GlobResource($this->dir . '/Handler', '/{*,**/*}.php', true),
        ], $this->stamp())));
        self::assertInstanceOf(SelfCheckingResourceInterface::class, $resource);

        return $resource;
    }

    private function stamp(): string
    {
        return $this->dir . '/globs-checked';
    }

    private function builtAt(): int
    {
        return time() - self::BUILT_AGO;
    }

    private function write(string $path): void
    {
        (new Filesystem())->dumpFile($this->dir . '/' . $path, '<?php');
        touch($this->dir . '/' . $path, time() - 2 * self::BUILT_AGO);
    }
}
