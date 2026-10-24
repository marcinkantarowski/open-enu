<?php

declare(strict_types=1);

namespace OpenEnu\Kernel\DependencyInjection;

use Symfony\Component\Config\Resource\GlobResource;
use Symfony\Component\Config\Resource\SelfCheckingResourceInterface;

/**
 * A glob that is not listed again while none of its directories changed.
 *
 * In debug every resource is checked on every request, and a glob checks
 * itself by listing its directories once more. Listing is what a macOS bind
 * mount does slowly - about a millisecond a directory - while a stat is close
 * to free. A file can only appear or go by changing its directory's mtime, so
 * while every directory under the glob keeps the mtime it had at build time
 * the listing would come out the same. Anything else - a changed or missing
 * directory, or one changed in the second the container was built, which a
 * whole-second mtime cannot order - is decided by the glob itself, as before.
 *
 * Docker Desktop reports a directory's new mtime up to about a second after the
 * file appears; a request inside that second may still see the old container.
 * Edits to a file's contents are tracked by other resources and are not this
 * one's concern.
 */
final class GuardedGlobResource implements SelfCheckingResourceInterface
{
    /** @var array<string, int> directory => mtime when the container was built */
    private readonly array $directories;

    public function __construct(private readonly GlobResource $glob)
    {
        $this->directories = self::directoriesUnder($glob->getPrefix(), self::depth($glob));
    }

    public function isFresh(int $timestamp): bool
    {
        foreach ($this->directories as $directory => $mtime) {
            $now = @filemtime($directory);
            if ($now !== $mtime || $now >= $timestamp) {
                return $this->glob->isFresh($timestamp);
            }
        }

        // A prefix that is no directory has nothing to stat: the glob decides.
        return $this->directories !== [] || $this->glob->isFresh($timestamp);
    }

    public function __toString(): string
    {
        return 'guarded.' . $this->glob;
    }

    /**
     * How deep under the prefix a match can sit: unbounded for a recursive
     * glob, otherwise one directory per `/` in the pattern.
     */
    private static function depth(GlobResource $glob): int
    {
        $pattern = (string) (new \ReflectionProperty(GlobResource::class, 'pattern'))->getValue($glob);
        $recursive = (bool) (new \ReflectionProperty(GlobResource::class, 'recursive'))->getValue($glob);

        return $recursive || str_contains($pattern, '**') ? -1 : max(0, substr_count($pattern, '/') - 1);
    }

    /** @return array<string, int> every directory a file under the prefix could appear in */
    private static function directoriesUnder(string $prefix, int $depth): array
    {
        if (!is_dir($prefix)) {
            return [];
        }

        $directories = [$prefix => (int) filemtime($prefix)];
        if ($depth === 0) {
            return $directories;
        }

        $tree = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($prefix, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );
        // Directories at the deepest level a match can sit in still count;
        // only what lies below them cannot hold a match.
        $tree->setMaxDepth($depth < 0 ? -1 : $depth - 1);

        foreach ($tree as $path => $entry) {
            \assert($entry instanceof \SplFileInfo);
            if ($entry->isDir()) {
                $directories[(string) $path] = (int) $entry->getMTime();
            }
        }

        return $directories;
    }
}
