<?php

declare(strict_types=1);

namespace OpenEnu\Kernel\Dev;

/**
 * Notices that the source changed, without listing all of it on every look.
 *
 * A development worker has to restart when the code does, and the bind mount
 * that carries the code into the container delivers no file events, so the
 * tree is polled. Polling with `find` lists every directory each time, and a
 * listing is what a macOS bind mount does slowly: about half a second for a
 * project's source, every two seconds, in every worker - and the API's own
 * requests queue behind it. A stat is close to free there.
 *
 * So every known file is stat-ed on every look - a file whose mtime, size or
 * inode moved has changed or been replaced, one that is gone was removed - and
 * the directories are listed a share at a time, all of them within
 * LISTING_ROUNDS looks, to find files that appeared. An edit is noticed on the
 * next look, a new file within LISTING_ROUNDS. A directory's mtime would say
 * where to look, but through Docker Desktop's file sharing it can stay stale
 * for hours after a file appears, while the listing is current.
 *
 * `$since` is when the worker started. A file stamped in that second or later
 * counts as changed - a whole-second mtime cannot tell "just before the worker
 * loaded it" from "just after", and one restart too many is cheap. A file
 * stamped in the future therefore restarts the worker every time, exactly as
 * it did under `find -newer`.
 */
final class SourceWatcher
{
    public const int LISTING_ROUNDS = 5;

    /** @var array<string, true> */
    private array $directories = [];

    /** @var array<string, string> file => mtime:size:inode */
    private array $files = [];

    private int $cursor = 0;

    /**
     * @param list<string> $roots      directories to watch, recursively
     * @param list<string> $extensions the files that count, e.g. `php`
     */
    public function __construct(array $roots, private readonly array $extensions, private readonly int $since)
    {
        foreach ($roots as $root) {
            $this->addDirectory(rtrim($root, '/'), false);
        }
    }

    /** The first file found changed, added or removed. Null: nothing. */
    public function changed(): ?string
    {
        clearstatcache();

        foreach ($this->files as $file => $signature) {
            $now = self::signature($file);
            if ($now !== $signature || (int) $signature >= $this->since) {
                return $file;
            }
        }

        $directories = array_keys($this->directories);
        $count = \count($directories);
        $share = (int) ceil($count / self::LISTING_ROUNDS);
        for ($i = 0; $i < $share; ++$i) {
            $directory = $directories[($this->cursor + $i) % $count];
            if (!is_dir($directory)) {
                // Whatever it held was a file above; an empty one is no code.
                unset($this->directories[$directory]);
                continue;
            }

            foreach (self::entries($directory) as $path) {
                if (is_dir($path)) {
                    if (!isset($this->directories[$path]) && ($found = $this->addDirectory($path, true)) !== null) {
                        return $found;
                    }
                } elseif ($this->counts($path) && !isset($this->files[$path])) {
                    return $path;
                }
            }
        }
        $this->cursor = $count === 0 ? 0 : ($this->cursor + $share) % $count;

        return null;
    }

    /**
     * Takes a directory in, with everything under it, and returns a file in it
     * that counts as changed already: any file at all when the directory is
     * new, since every file in it was added.
     */
    private function addDirectory(string $directory, bool $new): ?string
    {
        if (!is_dir($directory)) {
            return null;
        }

        $this->directories[$directory] = true;
        $found = null;
        foreach (self::entries($directory) as $path) {
            if (is_dir($path)) {
                $inside = $this->addDirectory($path, $new);
                $found ??= $inside;
            } elseif ($this->counts($path) && ($signature = self::signature($path)) !== null) {
                $this->files[$path] = $signature;
                if ($new || (int) $signature >= $this->since) {
                    $found ??= $path;
                }
            }
        }

        return $found;
    }

    private function counts(string $path): bool
    {
        return \in_array(pathinfo($path, \PATHINFO_EXTENSION), $this->extensions, true);
    }

    /** @return list<string> */
    private static function entries(string $directory): array
    {
        $names = @scandir($directory);

        return $names === false ? [] : array_values(array_map(
            static fn (string $name): string => $directory . '/' . $name,
            array_filter($names, static fn (string $name): bool => $name !== '.' && $name !== '..'),
        ));
    }

    private static function signature(string $file): ?string
    {
        $stat = @stat($file);

        return $stat === false ? null : $stat['mtime'] . ':' . $stat['size'] . ':' . $stat['ino'];
    }
}
