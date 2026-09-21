<?php

declare(strict_types=1);

namespace OpenEnu\Kernel\Storage;

/**
 * Where files go.
 *
 * Modules never touch the filesystem directly (StorageBoundaryRule). Three
 * reasons, and the first is the one that matters:
 *
 *  1. **Paths are tenant-scoped here, once.** A module writing files itself
 *     would have to remember the tenant prefix on every call, and the failure
 *     mode is one tenant reading another's uploads.
 *  2. Local in dev and S3 in production is a configuration change, not a
 *     rewrite.
 *  3. Downloads are served through signed, expiring URLs rather than by
 *     streaming bytes through PHP.
 */
interface StorageInterface
{
    /**
     * @param resource|string $contents
     *
     * @return string the storage key, to persist on the owning record
     */
    public function write(string $module, string $filename, mixed $contents, string $contentType): string;

    public function read(string $key): string;

    /** @return resource */
    public function readStream(string $key): mixed;

    public function delete(string $key): void;

    public function exists(string $key): bool;

    public function size(string $key): int;

    /**
     * A time-limited URL the browser can fetch directly.
     *
     * Short-lived on purpose: a storage key that grants permanent access is a
     * credential, and it will end up in a chat message.
     */
    public function temporaryUrl(string $key, int $ttlSeconds = 300): string;
}
