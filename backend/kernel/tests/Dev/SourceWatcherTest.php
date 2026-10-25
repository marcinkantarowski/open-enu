<?php

declare(strict_types=1);

namespace OpenEnu\Kernel\Tests\Dev;

use OpenEnu\Kernel\Dev\SourceWatcher;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

/**
 * What restarts a development worker, and what does not.
 *
 * The tree is made older than the worker, as a real one is, so that only what
 * each test does after the watcher started can count as a change.
 */
#[CoversClass(SourceWatcher::class)]
final class SourceWatcherTest extends TestCase
{
    /** The worker started this long ago; the tree is older still. */
    private const int STARTED_AGO = 20;

    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/open-enu-watch-' . bin2hex(random_bytes(4));
        $this->write('Module/Mail/Handler/SendHandler.php');
        $this->write('Module/Mail/module.yaml');
        $this->write('Module/Mail/README.md');
        $this->age();
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->dir);
    }

    public function testNothingChangedIsNothing(): void
    {
        $watcher = $this->watcher();

        self::assertNull($this->within($watcher));
        self::assertNull($this->within($watcher));
    }

    public function testAnEditedFileIsAChange(): void
    {
        $watcher = $this->watcher();
        touch($this->dir . '/Module/Mail/Handler/SendHandler.php', $this->startedAt() + 5);

        self::assertSame($this->dir . '/Module/Mail/Handler/SendHandler.php', $watcher->changed());
    }

    public function testAnEditInTheSecondTheWorkerStartedIsAChange(): void
    {
        // Same second, same size: only the rule about `since` can see it.
        touch($this->dir . '/Module/Mail/module.yaml', $this->startedAt());

        self::assertSame($this->dir . '/Module/Mail/module.yaml', $this->watcher()->changed());
    }

    public function testAnAddedFileIsAChangeEvenWithAnOldMtime(): void
    {
        $watcher = $this->watcher();
        $this->write('Module/Mail/Handler/RetryHandler.php');
        touch($this->dir . '/Module/Mail/Handler/RetryHandler.php', $this->startedAt() - 100);

        self::assertSame($this->dir . '/Module/Mail/Handler/RetryHandler.php', $this->within($watcher));
    }

    public function testAFileInANewDirectoryIsAChange(): void
    {
        $watcher = $this->watcher();
        $this->write('Module/Mail/Listener/Deep/OnSent.php');
        touch($this->dir . '/Module/Mail/Listener/Deep/OnSent.php', $this->startedAt() - 100);

        self::assertSame($this->dir . '/Module/Mail/Listener/Deep/OnSent.php', $this->within($watcher));
    }

    public function testARemovedFileIsAChange(): void
    {
        $watcher = $this->watcher();
        unlink($this->dir . '/Module/Mail/Handler/SendHandler.php');

        self::assertSame($this->dir . '/Module/Mail/Handler/SendHandler.php', $watcher->changed());
    }

    public function testFilesThatAreNotCodeAreNotChanges(): void
    {
        $watcher = $this->watcher();
        $this->write('Module/Mail/NOTES.md');
        $this->write('Module/Mail/Empty/.gitkeep');

        self::assertNull($this->within($watcher));
        // And the new directory is watched from now on.
        $this->write('Module/Mail/Empty/Late.php');
        touch($this->dir . '/Module/Mail/Empty/Late.php', $this->startedAt() - 100);
        self::assertSame($this->dir . '/Module/Mail/Empty/Late.php', $this->within($watcher));
    }

    public function testANewFileIsFoundEvenWhenItsDirectorysMtimeSaysOtherwise(): void
    {
        // Docker Desktop's file sharing can keep a directory's old mtime for
        // hours after a file appears in it; only the listing tells.
        $watcher = $this->watcher();
        $this->write('Module/Mail/Handler/Late.php');
        touch($this->dir . '/Module/Mail/Handler/Late.php', $this->startedAt() - 100);
        touch($this->dir . '/Module/Mail/Handler', $this->startedAt() - 100);

        self::assertSame($this->dir . '/Module/Mail/Handler/Late.php', $this->within($watcher));
    }

    /** What a worker's watcher reports within the looks it takes to list every directory once. */
    private function within(SourceWatcher $watcher): ?string
    {
        for ($i = 0; $i < SourceWatcher::LISTING_ROUNDS; ++$i) {
            if (($changed = $watcher->changed()) !== null) {
                return $changed;
            }
        }

        return null;
    }

    private function watcher(): SourceWatcher
    {
        return new SourceWatcher([$this->dir . '/'], ['php', 'yaml'], $this->startedAt());
    }

    private function startedAt(): int
    {
        return time() - self::STARTED_AGO;
    }

    private function write(string $path): void
    {
        (new Filesystem())->dumpFile($this->dir . '/' . $path, '<?php');
    }

    /** Everything that exists now predates the worker. */
    private function age(): void
    {
        $old = time() - 2 * self::STARTED_AGO;
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST) as $path => $entry) {
            touch((string) $path, $old);
        }
        touch($this->dir, $old);
    }
}
