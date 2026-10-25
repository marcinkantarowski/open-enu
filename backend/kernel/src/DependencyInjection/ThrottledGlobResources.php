<?php

declare(strict_types=1);

namespace OpenEnu\Kernel\DependencyInjection;

use Symfony\Component\Config\Resource\GlobResource;
use Symfony\Component\Config\Resource\SelfCheckingResourceInterface;

/**
 * Every glob the container was built from, listed at most once in a while.
 *
 * In debug every resource is checked on every request, and a glob checks
 * itself by listing its directories again - about a millisecond a directory
 * through a macOS bind mount, two hundred of them, on every request. A glob
 * only answers "was a file added or removed", so the answer is reused: once
 * all of them were found fresh, requests in the next SECONDS trust that.
 *
 * The cost is that a new file is noticed up to SECONDS late - a little more
 * for a request that arrives while another one is checking. The shortcut that
 * looks free - skipping the listing while each directory keeps its mtime - is
 * not: through Docker Desktop's file sharing a directory's mtime can stay stale
 * for hours after a file appears in it, while the listing is current. See the
 * lesson `a-freshness-check-runs-on-every-request`.
 *
 * The stamp lives in the cache directory, which is on a volume of the
 * container's own, where an mtime can be trusted.
 */
final class ThrottledGlobResources implements SelfCheckingResourceInterface
{
    public const int SECONDS = 2;

    /** @param list<GlobResource> $globs */
    public function __construct(private readonly array $globs, private readonly string $stamp)
    {
    }

    public function isFresh(int $timestamp): bool
    {
        $checked = @filemtime($this->stamp);
        if ($checked !== false && $checked >= time() - self::SECONDS) {
            return true;
        }

        // One request checks; the others, arriving with it - a page asks for
        // several things at once - keep the last answer meanwhile instead of
        // all listing the same tree at the same time, which is slower for each.
        // A file of its own: opening the stamp would create it, and an empty
        // new stamp reads as an answer just given.
        $lock = @fopen($this->stamp . '.lock', 'c');
        if ($lock === false) {
            return $this->check($timestamp);
        }

        try {
            if (!flock($lock, \LOCK_EX | \LOCK_NB)) {
                return true;
            }

            $fresh = $this->check($timestamp);
            if ($fresh) {
                @touch($this->stamp);
            }

            return $fresh;
        } finally {
            fclose($lock);
        }
    }

    private function check(int $timestamp): bool
    {
        foreach ($this->globs as $glob) {
            if (!$glob->isFresh($timestamp)) {
                return false;
            }
        }

        return true;
    }

    public function __toString(): string
    {
        return 'globs.' . hash('xxh128', implode("\n", array_map(strval(...), $this->globs)));
    }
}
